# 0002 Migrations Are Published, Not Loaded

## Context

A package can register its migrations from its own directory with `loadMigrationsFrom()`, or publish them into the application with `publishesMigrations()`. Doing both registers the same schema twice: once from the package path and once from the published copy, under two different file names. Laravel then runs both, and the second fails on tables that already exist.

Payment tables also attract application specific change. Merchants add their own columns, their own indexes, and sometimes a different connection or table prefix. A schema that lives inside `vendor/` cannot carry any of that, and is rewritten on every package update.

## Decision

Payline publishes its migrations under the `payline-migrations` tag and does not load them. Publishing is part of installation, not an optional step.

## Consequences

The schema belongs to the application. It can be edited before it is applied and it survives package updates.

Installation has one more required step, and an application that skips it fails on a missing table rather than on a missing migration. The installation page states the step and `payline:doctor` checks for all four tables.

A schema change in a future Payline version is not picked up automatically. It ships as an upgrade note with a migration the application publishes and reviews.

## Status

Accepted.
