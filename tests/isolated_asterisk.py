#!/usr/bin/env python3
"""Exercise ConfBridge on a separate, non-networked Asterisk instance.

Run on a Linux host with Asterisk and its modules installed:
    python3 tests/isolated_asterisk.py

This never connects to the host PBX socket, originates SIP calls, or loads
the host PBX configuration. All generated files live in a temporary directory.
"""

import os
import pathlib
import math
import shutil
import struct
import subprocess
import tempfile
import time
import wave


ASTERISK = os.environ.get("ASTERISK", "/usr/sbin/asterisk")
MODULES = os.environ.get("ASTERISK_MODULES", "/usr/lib/x86_64-linux-gnu/asterisk/modules")


def main():
    with tempfile.TemporaryDirectory(prefix="advancedpaging-asterisk-") as tmp:
        root = pathlib.Path(tmp)
        for dirname in ("etc", "spool", "run", "log", "data", "lib", "agi"):
            (root / dirname).mkdir()
        (root / "data/documentation").mkdir()
        for document in pathlib.Path("/var/lib/asterisk/documentation").glob("*.xml"):
            shutil.copy2(document, root / "data/documentation" / document.name)
        stylesheet = pathlib.Path("/var/lib/asterisk/documentation/appdocsxml.xslt")
        if stylesheet.is_file():
            shutil.copy2(stylesheet, root / "data/documentation" / stylesheet.name)
        (root / "data/sounds/en").mkdir(parents=True)
        recording = root / "data/sounds/en/post.wav"
        with wave.open(str(recording), "wb") as audio:
            audio.setnchannels(1)
            audio.setsampwidth(2)
            audio.setframerate(8000)
            samples = (int(8000 * math.sin(2 * math.pi * 440 * i / 8000)) for i in range(16000))
            audio.writeframes(b"".join(struct.pack("<h", sample) for sample in samples))
        capture = root / "receiver.wav"
        mic_recording = root / "data/sounds/en/microphone.wav"
        with wave.open(str(mic_recording), "wb") as audio:
            audio.setnchannels(1)
            audio.setsampwidth(2)
            audio.setframerate(8000)
            samples = (int(8000 * math.sin(2 * math.pi * 440 * i / 8000)) for i in range(48000))
            audio.writeframes(b"".join(struct.pack("<h", sample) for sample in samples))
        mute_capture = root / "mute-receiver.wav"
        config = root / "asterisk.conf"
        config.write_text(
            "[directories]\n"
            f"astetcdir => {root}/etc\n"
            f"astmoddir => {MODULES}\n"
            f"astvarlibdir => {root}/data\n"
            f"astdbdir => {root}/lib\n"
            f"astkeydir => {root}/data\n"
            f"astdatadir => {root}/data\n"
            f"astagidir => {root}/agi\n"
            f"astspooldir => {root}/spool\n"
            f"astrundir => {root}/run\n"
            f"astlogdir => {root}/log\n"
            "[options]\n"
            "verbose = 4\n"
            "nocolor = yes\n"
        )
        (root / "etc/modules.conf").write_text(
            "[modules]\n"
            "autoload=no\n"
            "load=res_timing_timerfd.so\n"
            "load=bridge_simple.so\n"
            "load=bridge_softmix.so\n"
            "load=codec_ulaw.so\n"
            "load=codec_alaw.so\n"
            "load=pbx_config.so\n"
            "load=res_clioriginate.so\n"
            "load=app_confbridge.so\n"
            "load=app_waitforring.so\n"
            "load=app_playback.so\n"
            "load=app_mixmonitor.so\n"
            "load=format_wav.so\n"
        )
        (root / "etc/confbridge.conf").write_text(
            "[test_bridge]\ntype=bridge\n"
            "[test_user]\ntype=user\nquiet=yes\n"
            "[test_sender]\ntype=user\nquiet=yes\nstartmuted=yes\n"
        )
        (root / "etc/extensions.conf").write_text(
            "[general]\nstatic=yes\nwriteprotect=yes\n"
            "[advancedpaging-lab]\n"
            f"exten => receiver,1,MixMonitor({capture})\n"
            " same => n,ConfBridge(aplab,test_bridge,test_user)\n"
            " same => n,Hangup()\n"
            "exten => initiator,1,ConfBridge(aplab,test_bridge,test_user)\n"
            " same => n,Hangup()\n"
            "exten => injector,1,ConfBridge(aplab,test_bridge,test_user)\n"
            " same => n,Hangup()\n"
            f"exten => listener,1,MixMonitor({mute_capture})\n"
            " same => n,ConfBridge(mutelab,test_bridge,test_user)\n"
            " same => n,Hangup()\n"
            "exten => microphone,1,ConfBridge(mutelab,test_bridge,test_sender)\n"
            " same => n,Hangup()\n"
        )
        log = (root / "server.log").open("w+")
        process = subprocess.Popen(
            [ASTERISK, "-f", "-n", "-C", str(config)],
            stdin=subprocess.DEVNULL, stdout=log, stderr=subprocess.STDOUT,
        )

        def cli(command):
            return subprocess.run(
                [ASTERISK, "-C", str(config), "-rx", command],
                stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True,
                timeout=8, check=True,
            ).stdout

        try:
            deadline = time.monotonic() + 12
            while not (root / "run/asterisk.ctl").exists():
                if process.poll() is not None or time.monotonic() > deadline:
                    log.flush()
                    log.seek(0)
                    raise RuntimeError("Isolated Asterisk did not start:\n" + log.read())
                time.sleep(0.1)
            while True:
                dialplan = cli("dialplan show advancedpaging-lab")
                if "ConfBridge(aplab" in dialplan:
                    break
                if process.poll() is not None or time.monotonic() > deadline:
                    log.flush()
                    log.seek(0)
                    raise RuntimeError("Isolated dialplan unavailable:\n" + dialplan + "\n" + log.read())
                time.sleep(0.1)
            version = cli("core show version")
            modules = cli("module show like app_confbridge")
            if "Running" not in modules:
                raise RuntimeError("ConfBridge did not load: " + modules)
            print(version.strip())
            print(modules.strip())
            print("Isolated dialplan loaded; originating Local test channels.")
            for name, duration in (("receiver", 30), ("initiator", 2)):
                output = cli(f"channel originate Local/{name}@advancedpaging-lab application Wait {duration}")
                if "failed" in output.lower() or "no such command" in output.lower():
                    raise RuntimeError("Originate failed: " + output)
            deadline = time.monotonic() + 8
            saw_both = False
            while time.monotonic() < deadline:
                participants = cli("confbridge list aplab")
                if "Local/receiver@" in participants and "Local/initiator@" in participants:
                    saw_both = True
                    break
                time.sleep(0.1)
            if not saw_both:
                raise RuntimeError("Both Local channels never joined: " + participants)
            print("Both initiator and receiver joined the isolated conference.")
            deadline = time.monotonic() + 8
            while time.monotonic() < deadline:
                participants = cli("confbridge list aplab")
                if "Local/receiver@" in participants and "Local/initiator@" not in participants:
                    break
                time.sleep(0.1)
            else:
                raise RuntimeError("Receiver did not survive initiator hangup: " + participants)
            print("Receiver remained connected after initiator hung up.")
            print(participants.strip())
            started = time.monotonic()
            output = cli("channel originate Local/injector@advancedpaging-lab application Playback post")
            if "failed" in output.lower() or "no such command" in output.lower():
                raise RuntimeError("Playback originate failed: " + output)
            deadline = time.monotonic() + 8
            seen_injector = False
            while time.monotonic() < deadline:
                participants = cli("confbridge list aplab")
                if "Local/receiver@" not in participants:
                    raise RuntimeError("Receiver disconnected during playback: " + participants)
                if "Local/injector@" in participants:
                    seen_injector = True
                elif seen_injector:
                    break
                time.sleep(0.1)
            else:
                raise RuntimeError("Playback injector did not complete: " + participants)
            log.flush()
            log.seek(0)
            server_log = log.read()
            if "Playing 'post" not in server_log:
                raise RuntimeError("Playback was not observed in the Asterisk log:\n" + server_log[-12000:])
            elapsed = time.monotonic() - started
            if elapsed < 1.6:
                raise RuntimeError(f"Two-second playback ended prematurely after {elapsed:.2f}s.")
            print(f"Playback channel completed after {elapsed:.2f}s with receiver still connected.")
            result = cli("confbridge kick aplab all")
            deadline = time.monotonic() + 5
            while time.monotonic() < deadline:
                remaining = cli("confbridge list aplab")
                if "Local/receiver@" not in remaining:
                    break
                time.sleep(0.1)
            else:
                raise RuntimeError("Receiver was not kicked: " + result + remaining)
            print("Receiver disconnected after playback completion.")
            deadline = time.monotonic() + 5
            while time.monotonic() < deadline:
                if capture.is_file() and capture.stat().st_size > 44:
                    try:
                        with wave.open(str(capture), "rb") as captured:
                            frames = captured.readframes(captured.getnframes())
                        values = struct.unpack("<" + "h" * (len(frames) // 2), frames)
                        tone_samples = sum(1 for sample in values if abs(sample) > 1000)
                        # The two-second, 8 kHz tone has about 14,600 samples
                        # above this threshold. Reject substantial truncation.
                        if tone_samples > 14000:
                            break
                    except (EOFError, wave.Error):
                        pass
                time.sleep(0.1)
            else:
                raise RuntimeError("Receiver capture did not contain the expected tone.")
            print(f"Receiver capture contained {tone_samples} audible-amplitude samples.")

            cli("channel originate Local/listener@advancedpaging-lab application Wait 20")
            deadline = time.monotonic() + 5
            while "Local/listener@" not in cli("confbridge list mutelab"):
                if time.monotonic() > deadline:
                    raise RuntimeError("Mute-test listener did not join.")
                time.sleep(0.1)
            cli("channel originate Local/microphone@advancedpaging-lab application Playback microphone")
            deadline = time.monotonic() + 5
            while "Local/microphone@" not in cli("confbridge list mutelab"):
                if time.monotonic() > deadline:
                    raise RuntimeError("Mute-test sender did not join.")
                time.sleep(0.1)
            time.sleep(1.5)
            cli("confbridge unmute mutelab Local/microphone@advancedpaging-lab")
            time.sleep(1.5)
            cli("confbridge mute mutelab Local/microphone@advancedpaging-lab")
            time.sleep(1.5)
            cli("confbridge kick mutelab all")
            time.sleep(0.5)
            with wave.open(str(mute_capture), "rb") as recorded:
                frames = recorded.readframes(recorded.getnframes())
            values = struct.unpack("<" + "h" * (len(frames) // 2), frames)
            if len(values) < 24000:
                raise RuntimeError("Mute-test audio capture is too short.")
            level = lambda data: sum(1 for sample in data if abs(sample) > 1000)
            beginning, middle, end = level(values[:8000]), level(values[8000:-8000]), level(values[-8000:])
            if beginning > 100 or middle < 3000 or end > 100:
                raise RuntimeError(f"Unexpected muted/unmuted audio: {beginning}, {middle}, {end}.")
            print(f"Muted/unmuted/muted receiver samples: {beginning}, {middle}, {end}.")
        finally:
            if process.poll() is None:
                process.terminate()
                try:
                    process.wait(timeout=8)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait()
            log.close()


if __name__ == "__main__":
    main()
