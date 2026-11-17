# Advanced Paging for FreePBX 17

**Current release: 17.0.0alpha6** — an unsigned alpha module for FreePBX 17
and Asterisk 22. It is not Sangoma-certified. Review the known platform and
handset limitations below before using it.

## Features

- Manage named paging groups, internal PJSIP receivers, and dialable extensions.
- One-way or duplex paging with optional pre-page and post-page System
  Recordings.
- Play an optional instruction to the caller alone before receiver admission.
- Set a speaker-volume override using the Paging module's Sangoma-compatible
  Alert-Info parameter.
- Choose Skip, Force, or Whisper behavior for busy extensions. Whisper uses
  ChanSpy whisper mode on busy PJSIP channels; endpoint support varies.
- Configure global **Drop Silence**, enabled by default to match FreePBX
  Paging. Asterisk discards audio it detects as silence before it enters the
  paging conference.
- Preserve caller ID on receiver calls.
- Serialize call events, prevent simultaneous calls to the same group, and
  reconcile module-owned channels after runtime restarts.
- Back up and restore group configuration without including runtime secrets.

See the [installation and acceptance guide](docs/INSTALL.md) and the
[17.0.0alpha6 release](https://github.com/srakrn/freepbx-advanced-paging/releases/tag/v17.0.0alpha6).

## Compatibility and limitations

- Requires FreePBX 17, Asterisk 22, PJSIP, PHP PCNTL/PDO MySQL, and systemd.
- Paging caller and receiver devices must be internal PJSIP extensions.
- Handset auto-answer and volume behavior depends on the device and firmware.
  The volume override is intended for Sangoma-compatible phones.
- Whisper relies on Asterisk ChanSpy and the target device's active channel.
  Validate it with the exact handset and call scenario; duplex is generally
  not useful with Whisper.
- If Asterisk restarts, active audio calls cannot be resumed. Recovery removes
  stale module-owned channels and conferences.
- The release is unsigned and not Sangoma-certified. It does not contain AMI
  credentials; those are provisioned locally at installation.

## Install

Download and verify the release archive and follow
[`docs/INSTALL.md`](docs/INSTALL.md):

```sh
VERSION=17.0.0alpha6
curl -fLO "https://github.com/srakrn/freepbx-advanced-paging/releases/download/v${VERSION}/advancedpaging-${VERSION}.tar.gz"
curl -fLO "https://github.com/srakrn/freepbx-advanced-paging/releases/download/v${VERSION}/advancedpaging-${VERSION}.tar.gz.sha256"
sha256sum -c "advancedpaging-${VERSION}.tar.gz.sha256"
```

The module manager provisions its loopback AMI account and protected runtime
configuration. The supplied systemd service must also be installed and
enabled. Installation does not send a page; create a non-critical test group
and follow the acceptance checks in the guide.

## Development and tests

Build a manual-deployment archive with:

```sh
sh scripts/build-module.sh dist
```

The archive is unsigned and contains no secret material. Basic tests require
PHP 7.4 or later and PDO SQLite:

```sh
for test in run controller asterisk_effects ami_resources group_lock \
  origination endpoint_resolver autoanswer runtime_snapshot runtime_config \
  freepbx_snapshot_adapter admin_page page_access schema backup_restore \
  freepbx_preinstall; do
  php "tests/${test}.php"
done
```

Additional integration tests:

```sh
python3 tests/isolated_asterisk.py
python3 tests/isolated_mariadb.py
```

The Asterisk test uses a separate temporary Asterisk process, temporary
configuration, and loopback-only AMI. It exercises playback, mute control,
origination, group concurrency, watchdogs, and crash recovery. The MariaDB
test uses a temporary private Unix socket and does not connect to a FreePBX
database. These tests do not replace real handset testing or a verified
FreePBX backup/restore exercise.
