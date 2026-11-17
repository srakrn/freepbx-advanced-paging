# Advanced Paging manual deployment checklist

This module has not received a production release or vendor signature. Review
the source and test results before installation. Do not install it directly on
a production system without a maintenance window and current FreePBX backup.

## Requirements

- FreePBX 17, Asterisk 22, and PJSIP.
- The `asterisk` Unix user and group.
- PHP PCNTL and PDO MySQL extensions.
- `systemd`.
- Applications > Paging and Intercom, Core, Recordings, and Backup modules.
- A restricted FreePBX administrator to manage groups.

## Install

1. Take a FreePBX backup and verify it is restorable.
2. Inspect the module archive and extract `advancedpaging/` into
   `/var/www/html/admin/modules/advancedpaging`, then set ownership:

   ```sh
   tar -xzf advancedpaging-17.0.0alpha3.tar.gz -C /var/www/html/admin/modules
   chown -R asterisk:asterisk /var/www/html/admin/modules/advancedpaging
   ```

   Ensure no web-accessible AMI credentials are included in the archive.
4. Install using FreePBX's module manager:

   ```sh
   fwconsole ma install advancedpaging
   fwconsole reload
   ```

   This archive is unsigned and is not Sangoma-certified. If the local
   installation policy blocks unsigned modules, review the source and use the
   FreePBX module administration setting for unsigned modules only if your
   organization's policy permits it. Do not import or fabricate a vendor
   signature.

   The BMO install provisions the extension-uniqueness index, a loopback-only
   AMI user in the module-owned include under `manager_custom.conf`, and a
   runtime credential file at `/etc/asterisk/advancedpaging-runtime.ini`.
   The credential file is restricted to the Asterisk service account. Do not
   copy it into a web directory, backup attachment, or shell transcript.

5. Review the generated AMI user and include, then validate the runtime secret
   file mode and owner (`asterisk:asterisk`, mode `0640`).
6. Reload the Asterisk Manager configuration so the dedicated local AMI user
   becomes active:

   ```sh
   asterisk -rx 'manager reload'
   ```

7. Install and start the foreground runtime service:

   ```sh
   install -o root -g root -m 0644 \
     /var/www/html/admin/modules/advancedpaging/packaging/advancedpaging.service \
     /etc/systemd/system/advancedpaging.service
   systemd-analyze verify /etc/systemd/system/advancedpaging.service
   systemctl daemon-reload
   systemctl enable --now advancedpaging.service
   systemctl status advancedpaging.service
   journalctl -u advancedpaging.service
   ```

8. Apply configuration in FreePBX. Enabled group extensions are generated in
   `from-internal-noxfer-additional`. The dialplan fails closed: if the service
   cannot admit a call, the caller is disconnected by the finite setup
   watchdog.
9. Create a test group with internal PJSIP receivers and verify setup, one-way
   and duplex behavior, opening/closing recordings, busy/unavailable receivers,
   repeated calls to one group, concurrent groups, and service restart.
10. Test with the actual target Grandstream GRP2602W and WP816 firmware. PBX
   headers are sent using the installed Paging module's defaults and user-agent
   overrides, but phones may ignore or reinterpret auto-answer headers.

## Upgrade and uninstall

- Stop the service before uninstall or upgrade operations that may replace its
  runtime code:

  ```sh
  systemctl stop advancedpaging.service
  ```

- Take a backup before upgrading. FreePBX's module backup stores group records
  and member relationships; it does not store runtime credentials.
- The uninstall hook refuses to remove AMI credentials while the runtime lock
  is held. Disable and stop the service first:

  ```sh
  systemctl disable --now advancedpaging.service
  fwconsole ma uninstall advancedpaging
  fwconsole reload
  asterisk -rx 'manager reload'
  ```

- Remove `/etc/systemd/system/advancedpaging.service` and run
  `systemctl daemon-reload` after uninstall if you installed the supplied unit.

- If the manager include or credential file was modified, the uninstall hook
  fails closed. Review the changes and remove module-owned configuration
  manually only after confirming no service instance is running.

## Security and recovery notes

- The runtime AMI account is bound through the local Asterisk manager and has
  call control, originate, reporting, user-event, and system privileges needed
  for recovery. These privileges are powerful. Do not expose AMI on a network
  interface or reuse the secret.
- The service is run as `asterisk`, uses a singleton lock, and reconciles
  Advanced Paging-owned conferences and channels before accepting calls.
- Asterisk restart drops active calls; this module cannot restore live audio
  sessions. Recovery cleans up remnants but does not resume a page.
- Runtime recovery and setup timeouts have been exercised in isolated Asterisk
  tests. Hardware auto-answer, upgrade/uninstall across a real FreePBX backup,
  and a production systemd installation still require operator acceptance
  testing.
