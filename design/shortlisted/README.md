# Shortlisted direction

The user shortlisted **Concept 05 — Case Timeline (Long-running tickets)**.

`case-timeline-original.html` is the preserved original Workflow Studio file. Open it at `#concept-5` to view the selected concept. Its checksum and the user's refinement brief are recorded in `selection.json`. Do not modify this snapshot when refining concepts.

The new comparison gallery is `../sahdev-timeline-refinements.html`. It embeds the original for direct comparison and offers eight additional, as-yet-unselected refinements:

- T01 — Timeline Plus
- T02 — Three-Lane Case Desk
- T03 — Evidence Ledger
- T04 — Split Chronicle
- T05 — Event Ribbon
- T06 — Case Journal
- T07 — Case Milestones
- T08 — Evidence to Action

The user's priority is timeline, case context, and findings, followed by custom instructions, intent, and technical context. Other controls should remain visible in the page. Analysis, Tools, Summary, KB, and Memory use inline tabs rather than modal or side-popout workflows.

Stars in the new gallery are stored in the local browser. The original remains pinned independently of browser storage. Further selections explicitly made in conversation should be recorded here or in the selection manifest.

To rebuild the standalone gallery, run `node design/build-timeline-refinements.cjs` from the repository root. The build reads the preserved snapshot and `timeline-round-2.extension.html`; it does not change the snapshot.
