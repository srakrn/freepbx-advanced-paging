# Advanced Paging — development foundation

**Not a FreePBX module yet. Do not install or deploy this code.** The paging
engine, administration interface, database integration, module packaging and
telephony tests remain to be implemented. In particular, this code does not
originate calls, control microphones, or play announcements.

The development-only `module.xml`, BMO adapter, and extension-usage hook are
an integration starting point, not a releasable package. The BMO installation
hook intentionally rejects installation. The schema still needs a verified
unique index on group extension before the guard can be removed.

The foundation contains a group validator, transactional repository, session
model, and a transport-independent session controller. The controller orders
effects and handles duplicate events, but it has no Asterisk effects adapter.
The eventual runtime must serialize events, atomically lock each group,
coordinate actual Asterisk operation completion, and clean up calls on failure.
An initial AMI effects adapter, synchronous connection, playback driver, and
known-channel resource registry exist. The event loop, unknown-origination
tracking, real group lock, and FreePBX dialplan integration are not implemented.

Run the basic tests with PHP 7.4 or later and the PDO SQLite extension:

```sh
php tests/run.php
php tests/controller.php
php tests/asterisk_effects.php
php tests/ami_resources.php
```

An isolated Asterisk lifecycle experiment is available on a Linux system with
the Asterisk binary, modules and XML documentation installed:

```sh
python3 tests/isolated_asterisk.py
```

It starts a second Asterisk process with temporary configuration and its own
socket, using only Local channels and a temporary loopback-only AMI listener,
and tears it down afterwards. It exercises
receiver survival after initiator hangup, captured playback audio, a timed
AMI playback injection and confirmed cancellation, AMI receiver hangup, and
AMI adapter ConfBridge mute/unmute
using a simulated microphone and a receiver capture. It does **not** verify automatic
production call control, PJSIP, or the module. On `eich-pbx` the installed
binary is 22.11.0 while the running PBX is 22.10.1.

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
