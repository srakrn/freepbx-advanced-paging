#!/usr/bin/env python3
"""Exercise ConfBridge on a separate, isolated Asterisk instance.

Run on a Linux host with Asterisk and its modules installed:
    python3 tests/isolated_asterisk.py

This never connects to the host PBX socket, originates SIP calls, or loads
the host PBX configuration. A temporary AMI listener binds only to localhost.
All generated files live in a temporary directory.
"""

import os
import pathlib
import math
import json
import re
import secrets
import shutil
import socket
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
        auto_capture = root / "automatic-receiver.wav"
        concurrent_captures = [root / "concurrent-1.wav", root / "concurrent-2.wav"]
        lock_dir = root / "locks"
        lock_dir.mkdir(mode=0o700)
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
            "load=app_userevent.so\n"
            "load=app_mixmonitor.so\n"
            "load=format_wav.so\n"
        )
        with socket.socket() as probe:
            probe.bind(("127.0.0.1", 0))
            ami_port = probe.getsockname()[1]
        ami_secret = secrets.token_hex(24)
        (root / "etc/manager.conf").write_text(
            f"[general]\nenabled=yes\nbindaddr=127.0.0.1\nport={ami_port}\n"
            f"[aplab]\nsecret={ami_secret}\nread=call,user\nwrite=call,originate\n"
        )
        (root / "etc/confbridge.conf").write_text(
            "[test_bridge]\ntype=bridge\n"
            "[test_user]\ntype=user\nquiet=yes\n"
            "[test_sender]\ntype=user\nquiet=yes\nstartmuted=yes\n"
        )
        (root / "etc/extensions.conf").write_text(
            "[general]\nstatic=yes\nwriteprotect=yes\n"
            "[advancedpaging-lab]\n"
            "exten => unanswered,1,Wait(15)\n"
            " same => n,Hangup()\n"
            f"exten => receiver,1,MixMonitor({capture})\n"
            " same => n,ConfBridge(ap-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb,test_bridge,test_user)\n"
            " same => n,Hangup()\n"
            "exten => initiator,1,ConfBridge(ap-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb,test_bridge,test_user)\n"
            " same => n,Hangup()\n"
            "exten => injector,1,ConfBridge(ap-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb,test_bridge,test_user)\n"
            " same => n,Hangup()\n"
            f"exten => listener,1,MixMonitor({mute_capture})\n"
            " same => n,ConfBridge(ap-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa,test_bridge,test_user)\n"
            " same => n,Hangup()\n"
            "exten => microphone,1,ConfBridge(ap-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa,test_bridge,test_sender)\n"
            " same => n,Hangup()\n"
            f"exten => auto-receiver,1,MixMonitor({auto_capture})\n"
            " same => n,ConfBridge(ap-cccccccccccccccccccccccccccccccc,test_bridge,test_sender)\n"
            " same => n,Hangup()\n"
            "exten => auto-caller,1,ConfBridge(ap-cccccccccccccccccccccccccccccccc,test_bridge,test_sender)\n"
            " same => n,Hangup()\n"
            f"exten => conc1-receiver,1,MixMonitor({concurrent_captures[0]})\n"
            " same => n,ConfBridge(ap-dddddddddddddddddddddddddddddddd,test_bridge,test_sender)\n"
            " same => n,Hangup()\n"
            "exten => conc1-caller,1,ConfBridge(ap-dddddddddddddddddddddddddddddddd,test_bridge,test_sender)\n"
            " same => n,Hangup()\n"
            f"exten => conc2-receiver,1,MixMonitor({concurrent_captures[1]})\n"
            " same => n,ConfBridge(ap-eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee,test_bridge,test_sender)\n"
            " same => n,Hangup()\n"
            "exten => conc2-caller,1,ConfBridge(ap-eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee,test_bridge,test_sender)\n"
            " same => n,Hangup()\n"
            "[advancedpaging-inject]\n"
            "exten => s,1,ConfBridge(${AP_CONF},test_bridge,test_user)\n"
            " same => n,Hangup()\n"
            "[advancedpaging-player]\n"
            "exten => s,1,Playback(${AP_SOUNDS})\n"
            " same => n,UserEvent(AdvancedPagingPlayback,PlaybackID:${AP_PLAYBACK_ID},Status:${PLAYBACKSTATUS})\n"
            " same => n,Hangup()\n"
            "[advancedpaging-receiver]\n"
            "exten => s,1,ConfBridge(${AP_CONF},test_bridge,test_sender)\n"
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
                if "ConfBridge(ap-bbbb" in dialplan:
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
            main_room = "ap-" + "b" * 32
            for name, duration in (("receiver", 30), ("initiator", 2)):
                output = cli(f"channel originate Local/{name}@advancedpaging-lab application Wait {duration}")
                if "failed" in output.lower() or "no such command" in output.lower():
                    raise RuntimeError("Originate failed: " + output)
            deadline = time.monotonic() + 8
            saw_both = False
            while time.monotonic() < deadline:
                participants = cli("confbridge list " + main_room)
                if "Local/receiver@" in participants and "Local/initiator@" in participants:
                    saw_both = True
                    break
                time.sleep(0.1)
            if not saw_both:
                raise RuntimeError("Both Local channels never joined: " + participants)
            print("Both initiator and receiver joined the isolated conference.")
            deadline = time.monotonic() + 8
            while time.monotonic() < deadline:
                participants = cli("confbridge list " + main_room)
                if "Local/receiver@" in participants and "Local/initiator@" not in participants:
                    break
                time.sleep(0.1)
            else:
                raise RuntimeError("Receiver did not survive initiator hangup: " + participants)
            print("Receiver remained connected after initiator hung up.")
            print(participants.strip())
            started = time.monotonic()
            env = dict(os.environ, AP_LAB_AMI_PORT=str(ami_port),
                       AP_LAB_AMI_SECRET=ami_secret, AP_LAB_CONFERENCE=main_room)
            playback_result = subprocess.run(
                ["php", str(pathlib.Path(__file__).parent / "ami_playback.php")],
                env=env, capture_output=True, text=True, timeout=15,
            )
            if playback_result.returncode:
                log.flush()
                log.seek(0)
                raise RuntimeError("AMI playback failed: " + playback_result.stderr + "\n" + log.read()[-12000:])
            print(playback_result.stdout.strip())
            participants = cli("confbridge list " + main_room)
            if "Local/receiver@" not in participants:
                raise RuntimeError("Receiver disconnected during playback: " + participants)
            log.flush()
            log.seek(0)
            server_log = log.read()
            if "Playing 'post" not in server_log:
                raise RuntimeError("Playback was not observed in the Asterisk log:\n" + server_log[-12000:])
            elapsed = time.monotonic() - started
            if elapsed < 1.6:
                raise RuntimeError(f"Two-second playback ended prematurely after {elapsed:.2f}s.")
            print(f"Playback channel completed after {elapsed:.2f}s with receiver still connected.")
            receiver_match = re.search(r"Local/receiver@advancedpaging-lab-[0-9a-fA-F]{8};2", participants)
            if not receiver_match:
                raise RuntimeError("Receiver channel name unavailable: " + participants)
            channel_info = cli("core show channel " + receiver_match.group())
            unique_match = re.search(r"UniqueID:\s*(\S+)", channel_info, re.IGNORECASE)
            if not unique_match:
                raise RuntimeError("Receiver unique ID unavailable: " + channel_info)
            env.update(AP_LAB_CHANNEL=receiver_match.group(), AP_LAB_UNIQUEID=unique_match.group(1))
            cleanup = subprocess.run(
                ["php", str(pathlib.Path(__file__).parent / "ami_cleanup.php")],
                env=env, capture_output=True, text=True, timeout=12,
            )
            if cleanup.returncode:
                raise RuntimeError("AMI cleanup failed: " + cleanup.stderr + cleanup.stdout)
            print(cleanup.stdout.strip())
            deadline = time.monotonic() + 5
            while time.monotonic() < deadline:
                remaining = cli("confbridge list " + main_room)
                if "Local/receiver@" not in remaining:
                    break
                time.sleep(0.1)
            else:
                raise RuntimeError("Receiver was not disconnected: " + remaining)
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

            mute_room = "ap-" + "a" * 32
            cli("channel originate Local/listener@advancedpaging-lab application Wait 20")
            deadline = time.monotonic() + 5
            while "Local/listener@" not in cli("confbridge list " + mute_room):
                if time.monotonic() > deadline:
                    raise RuntimeError("Mute-test listener did not join.")
                time.sleep(0.1)
            cli("channel originate Local/microphone@advancedpaging-lab application Playback microphone")
            deadline = time.monotonic() + 5
            while True:
                participants = cli("confbridge list " + mute_room)
                match = re.search(r"Local/microphone@advancedpaging-lab-[0-9a-fA-F]{8};2", participants)
                if match:
                    break
                if time.monotonic() > deadline:
                    raise RuntimeError("Mute-test sender did not join.")
                time.sleep(0.1)

            def ami_mute(muted):
                env = dict(os.environ, AP_LAB_AMI_PORT=str(ami_port),
                           AP_LAB_AMI_SECRET=ami_secret, AP_LAB_CONFERENCE=mute_room,
                           AP_LAB_CHANNEL=match.group(), AP_LAB_MUTE="1" if muted else "0")
                result = subprocess.run(
                    ["php", str(pathlib.Path(__file__).parent / "ami_mute.php")],
                    env=env, capture_output=True, text=True, timeout=6,
                )
                if result.returncode:
                    raise RuntimeError("AMI mute effect failed: " + result.stderr + result.stdout)

            time.sleep(1.5)
            ami_mute(False)
            time.sleep(1.5)
            ami_mute(True)
            time.sleep(1.5)
            cli("confbridge kick " + mute_room + " all")
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
            print(f"AMI adapter muted/unmuted/muted receiver samples: {beginning}, {middle}, {end}.")

            auto_room = "ap-" + "c" * 32
            for name, duration in (("auto-receiver", 30), ("auto-caller", 6)):
                cli(f"channel originate Local/{name}@advancedpaging-lab application Wait {duration}")
            deadline = time.monotonic() + 5
            while True:
                participants = cli("confbridge list " + auto_room)
                caller_match = re.search(r"Local/auto-caller@advancedpaging-lab-[0-9a-fA-F]{8};2", participants)
                receiver_match = re.search(r"Local/auto-receiver@advancedpaging-lab-[0-9a-fA-F]{8};2", participants)
                if caller_match and receiver_match:
                    break
                if time.monotonic() > deadline:
                    raise RuntimeError("Automatic session channels did not join: " + participants)
                time.sleep(0.1)

            def channel_unique_id(name):
                info = cli("core show channel " + name)
                found = re.search(r"UniqueID:\s*(\S+)", info, re.IGNORECASE)
                if not found:
                    raise RuntimeError("Channel identity unavailable: " + info)
                return found.group(1)

            auto_env = dict(os.environ, AP_LAB_AMI_PORT=str(ami_port), AP_LAB_AMI_SECRET=ami_secret,
                            AP_LAB_CONFERENCE=auto_room, AP_LAB_CALLER=caller_match.group(),
                            AP_LAB_CALLER_ID=channel_unique_id(caller_match.group()),
                            AP_LAB_CHANNEL=receiver_match.group(),
                            AP_LAB_UNIQUEID=channel_unique_id(receiver_match.group()))
            automatic = subprocess.run(
                ["php", str(pathlib.Path(__file__).parent / "ami_session.php")],
                env=auto_env, capture_output=True, text=True, timeout=20,
            )
            if automatic.returncode:
                log.flush()
                log.seek(0)
                raise RuntimeError("Automatic session failed: " + automatic.stderr + automatic.stdout + "\n" + log.read()[-12000:])
            print(automatic.stdout.strip())
            if "Local/auto-receiver@" in cli("confbridge list " + auto_room):
                raise RuntimeError("Automatic session left its receiver connected.")
            with wave.open(str(auto_capture), "rb") as recorded:
                frames = recorded.readframes(recorded.getnframes())
            samples = struct.unpack("<" + "h" * (len(frames) // 2), frames)
            automatic_tone = sum(1 for sample in samples if abs(sample) > 1000)
            if automatic_tone < 14000:
                raise RuntimeError(f"Automatic closing playback was truncated: {automatic_tone} samples.")
            print(f"Automatic receiver captured {automatic_tone} audible-amplitude samples.")

            originate_env = dict(os.environ, AP_LAB_AMI_PORT=str(ami_port), AP_LAB_AMI_SECRET=ami_secret,
                                 AP_LAB_CONFERENCE="ap-" + "f" * 32)
            origination = subprocess.run(
                ["php", str(pathlib.Path(__file__).parent / "ami_origination.php")],
                env=originate_env, capture_output=True, text=True, timeout=12,
            )
            if origination.returncode:
                log.flush()
                log.seek(0)
                raise RuntimeError("Pending origination test failed: " + origination.stderr + origination.stdout + "\n" + log.read()[-12000:])
            print(origination.stdout.strip())
            if "Local/unanswered@advancedpaging-lab" in cli("core show channels concise"):
                raise RuntimeError("Cancelled Local origination left a channel behind.")

            for name, duration in (("conc1-receiver", 35), ("conc2-receiver", 35),
                                   ("conc1-caller", 6), ("conc2-caller", 11)):
                cli(f"channel originate Local/{name}@advancedpaging-lab application Wait {duration}")
            settings = []
            for index, letter in ((1, "d"), (2, "e")):
                room = "ap-" + letter * 32
                deadline = time.monotonic() + 4
                while True:
                    participants = cli("confbridge list " + room)
                    caller_match = re.search(fr"Local/conc{index}-caller@advancedpaging-lab-[0-9a-fA-F]{{8}};2", participants)
                    receiver_match = re.search(fr"Local/conc{index}-receiver@advancedpaging-lab-[0-9a-fA-F]{{8}};2", participants)
                    if caller_match and receiver_match:
                        break
                    if time.monotonic() > deadline:
                        raise RuntimeError("Concurrent channels did not join: " + participants)
                    time.sleep(0.1)
                settings.append({"room": room, "caller": caller_match.group(),
                                 "caller_id": channel_unique_id(caller_match.group()),
                                 "receiver": receiver_match.group(),
                                 "receiver_id": channel_unique_id(receiver_match.group())})
            concurrent_env = dict(os.environ, AP_LAB_AMI_PORT=str(ami_port), AP_LAB_AMI_SECRET=ami_secret,
                                  AP_LAB_LOCK_DIR=str(lock_dir), AP_LAB_SESSIONS=json.dumps(settings))
            concurrent = subprocess.run(
                ["php", str(pathlib.Path(__file__).parent / "ami_concurrent.php")],
                env=concurrent_env, capture_output=True, text=True, timeout=28,
            )
            if concurrent.returncode:
                log.flush()
                log.seek(0)
                raise RuntimeError("Concurrent session failed: " + concurrent.stderr + concurrent.stdout + "\n" + log.read()[-12000:])
            print(concurrent.stdout.strip())
            for capture in concurrent_captures:
                with wave.open(str(capture), "rb") as recorded:
                    frames = recorded.readframes(recorded.getnframes())
                samples = struct.unpack("<" + "h" * (len(frames) // 2), frames)
                count = sum(1 for sample in samples if abs(sample) > 1000)
                if count < 14000:
                    raise RuntimeError(f"Concurrent closing playback was truncated: {count} samples.")
                print(f"Concurrent receiver captured {count} audible-amplitude samples.")
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
