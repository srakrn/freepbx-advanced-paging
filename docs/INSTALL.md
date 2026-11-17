# Advanced Paging installation guide

Advanced Paging **17.0.0** is the first stable, unsigned release for FreePBX 17
and Asterisk 22. It is not a Sangoma-certified module. Review these
instructions, the release notes, and your local change-control requirements
before installing it.

## Requirements

- FreePBX 17 with Asterisk 22 and PJSIP.
- FreePBX Core, Paging, Recordings, and Backup modules.
- PHP PCNTL and PDO MySQL extensions.
- `systemd`, and the `asterisk` Unix account/group.
- A FreePBX administrator authorized to manage paging groups.

The caller and receiver extensions must be internal PJSIP devices. The module
uses the FreePBX Paging module's auto-answer settings. Phone support for
auto-answer and speaker-volume headers varies by vendor and firmware.

## Download and verify

Download the archive and checksum from the
[17.0.0 GitHub release](https://github.com/srakrn/freepbx-advanced-paging/releases/tag/v17.0.0):

```sh
VERSION=17.0.0
curl -fLO "https://github.com/srakrn/freepbx-advanced-paging/releases/download/v${VERSION}/advancedpaging-${VERSION}.tar.gz"
curl -fLO "https://github.com/srakrn/freepbx-advanced-paging/releases/download/v${VERSION}/advancedpaging-${VERSION}.tar.gz.sha256"
sha256sum -c "advancedpaging-${VERSION}.tar.gz.sha256"
tar -tzf "advancedpaging-${VERSION}.tar.gz"
```

The archive contains the module source and documentation, but no AMI secret or
runtime credential file. The checksum verifies integrity, not publisher
identity; verify the release/tag through your normal GitHub trust process.

## Install or upgrade

1. Schedule a maintenance window, take a current FreePBX backup, and confirm
   that you can restore it. Do not upgrade while a paging call is active.
2. For an existing installation, stop the runtime before replacing its files:

   ```sh
   systemctl stop advancedpaging.service
   ```

3. Extract the module into FreePBX's module directory and set ownership:

   ```sh
   tar -xzf "advancedpaging-${VERSION}.tar.gz" \
     -C /var/www/html/admin/modules
   chown -R asterisk:asterisk \
     /var/www/html/admin/modules/advancedpaging
   ```

4. Install/update through the FreePBX module manager and reload Asterisk:

   ```sh
   fwconsole ma install advancedpaging --no-interaction
   asterisk -rx 'manager reload'
   fwconsole reload --no-interaction
   ```

   The module manager reports the installed version. The module provisions its
   loopback AMI account and protected runtime configuration at
   `/etc/asterisk/advancedpaging-runtime.ini`; verify that the file is owned by
   `asterisk:asterisk` and mode `0640`. Do not copy the secret into a web
   directory or share it in logs or support requests.

5. Install or replace the supplied service unit. If you have local unit
   customizations, save and review them before replacing the file:

   ```sh
   install -o root -g root -m 0644 \
     /var/www/html/admin/modules/advancedpaging/packaging/advancedpaging.service \
     /etc/systemd/system/advancedpaging.service
   systemd-analyze verify /etc/systemd/system/advancedpaging.service
   systemctl daemon-reload
   systemctl enable --now advancedpaging.service
   ```

6. Confirm the service is active and review its startup log:

   ```sh
   systemctl is-active advancedpaging.service
   systemctl status advancedpaging.service --no-pager
   journalctl -u advancedpaging.service --since today
   ```

7. In FreePBX, apply configuration. Enabled group extensions are routed from
   the internal dialplan. Confirm the group's destination appears in Asterisk's
   dialplan before testing. If the service cannot admit a caller, the setup
   watchdog hangs up rather than leaving the caller in an unattended bridge.

## Configure groups

In **Applications → Advanced Paging**, create or edit a group, choose internal
PJSIP receiver extensions, and save. Apply configuration after changes.

- **Drop Silence:** A global setting under **Settings**, enabled by default.
  Asterisk discards audio detected as silence before it enters the paging
  conference. The setting applies to all paging participants, including
  busy-extension Whisper sessions.
- **Audio mode:** One-way (simplex) or duplex.
- **Caller instruction:** Plays to the caller alone before receiver admission
  and before the pre-page recording.
- **Pre-page and post-page recordings:** System Recordings used for the page
  lifecycle.
- **Speaker Volume Override:** None or levels 1–14. This sends the Paging
  module's Sangoma-compatible Alert-Info volume parameter; other phones may
  ignore it.
- **Busy Extensions:**
  - **Skip** omits busy receivers; idle and on-hold receivers may still be
    paged.
  - **Force** attempts every registered receiver regardless of device state.
    A handset may interrupt an existing conversation.
  - **Whisper** pages idle/on-hold receivers normally and uses Asterisk
    ChanSpy whisper mode (`qW`) for busy PJSIP channels. Whisper behavior
    depends on Asterisk and endpoint support. Test it with the specific
    handset and call path; duplex is generally not a useful pairing with
    whispering.

The caller's caller ID is passed to receiver calls. Caller ID presentation and
auto-answer behavior can still be affected by handset settings and firmware.

## Acceptance checks

Before relying on a group, test with non-critical extensions:

- caller instruction, pre-page tone, one-way audio, and duplex audio;
- caller ID as displayed by each receiver handset;
- Skip, Force, and Whisper with idle and busy devices;
- unavailable/unregistered devices and missing recordings;
- repeated calls to the same group and calls to separate groups;
- service restart and cleanup after an Asterisk restart.

Do not use an emergency group as the first test of Force or Whisper. No live
page is sent automatically by installation or Apply Config.

## Backup, upgrade, and rollback

FreePBX's module backup hooks include group settings and receiver membership;
the runtime AMI secret is deliberately excluded. Keep a separate, protected
copy of the module-owned files and current FreePBX backup before an upgrade.

For rollback, stop the service, restore the previously saved module directory
and FreePBX backup as appropriate, then run `fwconsole reload` and start the
service. The stable migration adds a settings table and option columns with
safe defaults; older module code ignores them. Alpha5 cannot read backup format
version 3, so restore the pre-upgrade backup when rolling back to alpha5.
Prefer restoring the full FreePBX backup if rollback also needs to undo group
edits made after upgrade.

## Uninstall

Stop and disable the service before uninstalling. The uninstall hook refuses to
remove the AMI account while the runtime lock is held:

```sh
systemctl disable --now advancedpaging.service
fwconsole ma uninstall advancedpaging
fwconsole reload
asterisk -rx 'manager reload'
rm -f /etc/systemd/system/advancedpaging.service
systemctl daemon-reload
```

If the module-owned manager include or runtime credential was modified, the
hook fails closed. Review changes and confirm no runtime process remains before
manually removing only module-owned files. Do not delete site-wide
`manager_custom.conf`.

## Security notes

The dedicated AMI account is powerful and is intended for loopback access only.
Do not expose the Asterisk Manager Interface on a network interface or reuse
the generated secret. The foreground service runs as `asterisk`, takes a
singleton runtime lock, and reconciles Advanced Paging-owned channels before
accepting calls. Recovery cleans up abandoned calls; it cannot restore live
audio after an Asterisk restart.
