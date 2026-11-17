<?php

// FreePBX 17 migrates module.xml database tables before calling the BMO
// install() method. Abort in its pre-install include phase instead, so this
// development preview cannot create or alter tables on an existing PBX.
throw new RuntimeException('Advanced Paging is a development preview and cannot be installed.');
