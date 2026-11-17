# Advanced Paging — development foundation

**Development preview. Do not install or deploy this code.** The module has
an administration page and experimental telephony components, but its
installation, runtime integration and acceptance testing are incomplete.

The development-only `module.xml`, BMO adapter, and extension-usage hook are
an integration starting point, not a releasable package. A FreePBX pre-install
include rejects installation before table migration, and the BMO installation
hook also rejects it. The module XML declares the unique group-extension
index. FreePBX 17's actual XML migration implementation and the standalone
schema check have been tested against temporary MariaDB; full module
installation has not been tested.

The foundation contains a group validator, transactional repository, session
model, and a transport-independent session controller. The controller orders
effects and handles duplicate events.
The eventual runtime must serialize events, atomically lock each group,
coordinate actual Asterisk operation completion, and clean up calls on failure.
An initial AMI effects adapter, synchronous connection, playback driver,
known-channel resource registry, shared event router, and process-held per-group
locks exist. An experimental AMI origination tracker preassigns channel
identifiers, feeds the session event router, and waits for cancellation
evidence. A FreePBX Core/AstDB snapshot loader excludes the caller and
non-idle devices, and snapshots recording references for each session.
An in-process session runtime coordinates locking, origination, and setup
deadlines after a caller has joined. An admission coordinator and pre-answer
dialplan watchdog are exercised in isolation. A runtime bootstrap acquires a
singleton lock and reconciles owned rooms and channels before admission.
The development-preview foreground entry point now uses the FreePBX loader and
an external protected AMI credential file; a systemd unit is supplied as an
uninstalled template. Its service loop and recovery have only been tested with
an isolated Asterisk and test configuration adapter. Deployment, real FreePBX
bootstrap, systemd operation, and recovery across all failure modes remain
unverified. An experimental
dialable group route generator exists but is
intentionally not invoked by the FreePBX configuration hook; worker contexts
are generated but not deployed. An OS lock alone does not clean up channels
after a crash.

Run the basic tests with PHP 7.4 or later and the PDO SQLite extension:

```sh
php tests/run.php
php tests/controller.php
php tests/asterisk_effects.php
php tests/ami_resources.php
php tests/group_lock.php
php tests/origination.php
php tests/endpoint_resolver.php
php tests/autoanswer.php
php tests/runtime_snapshot.php
php tests/runtime_config.php
php tests/freepbx_snapshot_adapter.php
php tests/admin_page.php
php tests/page_access.php
php tests/schema.php
php tests/backup_restore.php
php tests/freepbx_preinstall.php
```

An isolated Asterisk lifecycle experiment is available on a Linux system with
the Asterisk binary, modules and XML documentation installed:

```sh
python3 tests/isolated_asterisk.py
```

The MySQL-specific schema migration can be tested on a Linux host with
MariaDB server tools and PHP's PDO MySQL extension:

```sh
python3 tests/isolated_mariadb.py
```

It starts a separate temporary MariaDB instance using a private Unix socket
and no network listener; it does not connect to the FreePBX database. It runs
FreePBX 17's XML table migration twice and verifies the transactional tables
and unique extension index.

It starts a second Asterisk process with temporary configuration and its own
socket, using only Local channels and a temporary loopback-only AMI listener,
and tears it down afterwards. It exercises
receiver survival after initiator hangup, captured playback audio, a timed
AMI playback injection and confirmed cancellation, AMI receiver hangup, and
AMI adapter ConfBridge mute/unmute using a simulated microphone and a receiver
capture. A separate scenario exercises the session controller and event pump:
caller hangup triggers closing playback and receiver cleanup. A second
scenario routes two concurrent sessions through one AMI connection and
exercises real per-group locks. A separate test cancels an unanswered
AMI-originated Local channel and confirms that both Local legs ended. Another
scenario originates an answering Local receiver and an unanswered receiver,
cancels the unanswered call at setup close, then checks automatic closing audio
and cleanup. Worker dialplan is rendered using the installed FreePBX command
classes. Another test dials an experimentally generated group route through the
admission coordinator and the foreground service loop with a test snapshot
adapter. The test checks that unacknowledged and same-group callers are
rejected before conference admission. It kills a running test controller and
checks that the next bootstrap removes its abandoned channels without
disconnecting an unrelated conference.
A synthetic loopback PJSIP endpoint also receives an INVITE with
FreePBX's default Alert-Info and Call-Info headers, answers, joins, and receives
a BYE on cleanup. It answers unconditionally; the test does **not** demonstrate
that a particular phone firmware honors those headers. It also does not verify
FreePBX endpoint resolution on actual phones, production failure recovery,
device-specific paging settings, or the module.

Backup and restore hooks export group configuration and declare Core and
System Recordings dependencies. Restore creates groups transactionally and
rejects conflicts rather than overwriting existing groups. Referenced extensions
and recording IDs must already exist. The hooks have been tested with mock
FreePBX backup adapters and isolated databases, not a real FreePBX backup job.

On Asterisk 22.11, asynchronous AMI Originate was observed not to run
`PreDialGoSub`. PJSIP calls therefore use a module-owned Local-channel wrapper
and `Dial()`'s callee-side pre-dial hook. The isolated test uses the installed
FreePBX `autoanswer`, `func-set-sipheader`, and `func-apply-sipheaders`
contexts, with a test stub for the P-series helper.
On `eich-pbx` the installed
binary is 22.11.0 while the running PBX is 22.10.1.

`packaging/advancedpaging.service` and `bin/advancedpaging-runtime` are
development previews and must not be installed yet. The dedicated AMI
credential file is expected at `/etc/asterisk/advancedpaging-runtime.ini` and
must not be world-readable or group-writable. Startup reconciliation requires
AMI reporting and system privileges for channel listing on the tested
Asterisk version; these privileges are broad and require security review before
deployment. No credential file or service has been installed.

## Platform investigation

The FreePBX 17 Paging module uses `framework_check_extension_usage()` for
extension conflicts and its existing auto-answer dialplan for signalling.
The FreePBX 17 Recordings module exposes `getRecordingById()` and
`getAllRecordingsList()`; recordings can contain multiple files. Integration
must preserve compound recordings and language selection rather than using
only the first file.

Sources:

- https://github.com/FreePBX/paging/blob/release/17.0/Paging.class.php
- https://github.com/FreePBX/recordings/blob/release/17.0/Recordings.class.php
- https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ConfbridgeMute/
- https://docs.asterisk.org/Configuration/Applications/Conferencing-Applications/ConfBridge/ConfBridge-AMI-Actions/

## Remaining acceptance gate

Before a deployable module is packaged, demonstrate on FreePBX 17 and the
actual Asterisk 22 patch version that the chosen architecture reliably stops
opening playback, mutes microphones, plays the complete closing recording
after initiator hangup, and disconnects receivers on completion or failure.
Test late answers, simultaneous calls, event loss, reload and restart. Select
native dialplan, AMI or ARI only after this proof; do not treat the pure state
model as evidence that these telephony guarantees are met.
