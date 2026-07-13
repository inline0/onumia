---
title: "History"
meta_title: "Onumia History"
meta_description: "How Onumia versions custom modules and custom apps with Git history: what creates commits, previewing versions, and reverting."
path: "history"
order: 350
section: "Customization"
---

# History

Every custom module and every custom app carries its own version history. Onumia manages a small Git repository inside each custom entity's folder and commits on every meaningful change, so editing a custom module is never a leap of faith: you can see what changed, preview any earlier version, and revert to it.

History applies only to custom entities. Bundled modules are read-only catalog content, so there is nothing of yours to version until you remix.

## What creates a version

Versions are created automatically; there is no manual commit step.

1. Remixing a module, or creating one from scratch, records the initial version.
2. Saving settings for a custom module records a version when something changed.
3. Saving file edits, manual or agent-made, records a version when the files changed.
4. Reverting records the restore itself as a new version.

Settings ride along with files. Onumia mirrors the module's saved settings into the repository alongside its files, so a version captures the whole state of the module at that moment: screen definition, behavior, messages, and configuration together.

## Browsing history

The History tab in the custom entity's sidebar lists versions as compact rows built from commit metadata: when the change happened, who made it, and how much changed. Selecting a version previews the module exactly as it existed then. The preview is read-only, the rendered settings screen and values reflect that version, and the dashboard makes the mode obvious: while previewing, the Save button in the header becomes Revert.

Switching between versions is instant, because the dashboard loads prepared snapshots with the history list rather than fetching each version on click.

## Reverting

Revert restores the selected version's files into the module's folder, restores its mirrored settings as the saved settings, and records the restore as a new commit on top of the existing history. Nothing is rewritten or deleted: the versions you reverted away from remain in the list, so a revert is itself reversible.

This makes experimentation cheap. An agent edit that went sideways, a screen restructure you regret, or a behavior change that proved wrong is one revert away from gone, with the full trail preserved.

## Where history lives

The repository sits inside the custom entity's own folder in the active theme, under a normal `.git` directory, with the settings mirror stored under a `.onumia/` folder next to the module files. It is internal product state, not a repository you are expected to manage by hand, but its presence has a practical upside: the history travels with the theme folder wherever the theme goes.

If you also keep your theme in version control of your own, the custom module's internal repository is simply part of the files in your working tree; your tooling may ignore nested repositories or treat them as submodule-like entries depending on configuration. Onumia only ever operates on the module's own repository.
