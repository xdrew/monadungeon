<!-- aif:research-mode:ultra -->
# Research Index: Karak game mechanics vs the rules

Topic: Full audit of the game mechanics against the official Karak and Karak: Regent rules
Slug: karak-rules-compliance
Updated: 2026-10-17 12:45
Status: active

## Purpose
Which implemented mechanics (server and browser) deviate from the official base and Regent rulebooks or from `rules.md`, how severe each deviation is, and how to fix it. The boundary is game rules only: no visuals, audio or online infrastructure beyond rule bypasses.

## Artifact Index

| Artifact | Purpose | Why included | Status |
|----------|---------|--------------|--------|
| [RESEARCH.md](RESEARCH.md) | Active summary, the findings catalogue (F-*, D-*), decisions (DEC-*), sessions | Required | active |

No C4, ADR or dependency graph: the whole topic lives inside one application (Symfony backend + its Vue client) whose structure is documented in `CLAUDE.md`. The rule decisions are recorded as DEC-* in RESEARCH.md; none needed an ADR.

## Reading Order
1. [RESEARCH.md](RESEARCH.md)
