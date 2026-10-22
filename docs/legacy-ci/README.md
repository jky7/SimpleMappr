# Archived Travis setup

These files preserve the upstream CI configuration and helper scripts for historical reference. Their original paths and commands describe the retired runtime and are not an executable installation recipe from this directory.

The maintained [release workflow](../../.github/workflows/release.yml) builds the pinned Docker runtime and runs native rendering, PHP, import, persistence, browser and recovery checks. Its [first complete run passed](https://github.com/jky7/SimpleMappr/actions/runs/39718169668) before this archive was created.

The original tests remain under `Tests/`. Their archived Selenium and database bootstrap assumptions still require migration; the maintained checks do not claim that those original suites run.
