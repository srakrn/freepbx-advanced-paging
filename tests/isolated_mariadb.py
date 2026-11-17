#!/usr/bin/env python3
"""Run the MySQL-specific migration test on a temporary, socket-only server."""

import os
import pathlib
import subprocess
import tempfile
import time


def main():
    with tempfile.TemporaryDirectory(prefix="advancedpaging-mariadb-") as tmp:
        root = pathlib.Path(tmp)
        data = root / "data"
        data.mkdir()
        result = subprocess.run(
            ["/usr/bin/mariadb-install-db", "--no-defaults", f"--datadir={data}",
             "--auth-root-authentication-method=normal", "--skip-test-db"],
            capture_output=True, text=True, timeout=45,
        )
        if result.returncode:
            raise RuntimeError("Isolated MariaDB initialization failed:\n" + result.stderr[-3000:])
        sock = root / "mysql.sock"
        log = root / "mysql.log"
        process = subprocess.Popen(
            ["/usr/sbin/mariadbd", "--no-defaults", f"--datadir={data}",
             f"--socket={sock}", f"--pid-file={root / 'mysql.pid'}",
             f"--log-error={log}", "--skip-networking", "--innodb-buffer-pool-size=32M"],
            stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        )
        try:
            deadline = time.monotonic() + 20
            while not sock.exists():
                if process.poll() is not None or time.monotonic() > deadline:
                    raise RuntimeError("Isolated MariaDB did not start:\n" + (log.read_text() if log.exists() else ""))
                time.sleep(0.1)
            subprocess.run(
                ["/usr/bin/mariadb", "--no-defaults", "-uroot", f"--socket={sock}",
                 "-e", "CREATE DATABASE ap_test; CREATE DATABASE ap_freepbx_test"],
                capture_output=True, text=True, timeout=10, check=True,
            )
            env = dict(os.environ, AP_LAB_MYSQL_SOCKET=str(sock))
            result = subprocess.run(
                ["php", str(pathlib.Path(__file__).parent / "mysql_schema.php")],
                env=env, capture_output=True, text=True, timeout=15,
            )
            if result.returncode:
                raise RuntimeError(result.stderr + result.stdout)
            print(result.stdout.strip())
            result = subprocess.run(
                ["php", str(pathlib.Path(__file__).parent / "freepbx_schema.php")],
                env=env, capture_output=True, text=True, timeout=20,
            )
            if result.returncode:
                raise RuntimeError(result.stderr + result.stdout)
            print(result.stdout.strip())
        finally:
            if process.poll() is None:
                process.terminate()
                try:
                    process.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait()


if __name__ == "__main__":
    main()
