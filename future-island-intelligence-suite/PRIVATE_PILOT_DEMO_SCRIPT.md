# Private Pilot Demo Script

A ~12–15 minute walkthrough that shows one working, evidence-backed, reviewable loop —
without overclaiming. Do not promise providers, publishing, or production readiness.

> Setup: logged-in admin, plugin active. A seeded workspace/run already exists (or run
> Brand & Market X-Ray live). No OpenAI/Apify keys are required for this demo.

1. **Open with the cultural-tech argument.**
   "Future Island is a cultural-tech marketing intelligence workspace. It is not an AI
   writer or a scraper. It turns signals into evidence, evidence into reviewable insights
   and briefs, and review decisions into reusable decision memory."

2. **Show Signal Room / Command Room.**
   Open Command Room. Point out the three columns: command canvas (not a blank chat),
   playbook launcher, and the evidence/object drawer. "Evidence first. Generation second.
   Memory after review."

3. **Open or create a workspace.**
   Show the workspace resolved server-side (#1) and the onboarding progress strip.

4. **Add a manual/URL source or fixture.**
   Use Brand & Market X-Ray to store brand context + a reference URL. "This stores URLs as
   reference sources only — no crawling or provider call is required."

5. **Show run / timeline.**
   Open the Run Timeline. Walk the stages (Run created → Provider/auth → Rows validated →
   Review pending) and note "signature redacted" — secrets never appear.

6. **Show signal / evidence.**
   In the evidence drawer, show the normalized signals and evidence refs.

7. **Show the insight and its evidence limits.**
   Open the insight: separate **Observado** from **Inferido**, and read the **Confianza**
   and **Riesgo: evidencia limitada** chips. "We label what we saw vs what we infer, and we
   flag thin evidence."

8. **Approve / review the insight.**
   Use the review actions (Approve / Reject / request revision). "Generation stays blocked
   until a human approves — this is the learning layer."

9. **Convert to brief.**
   Show the brief candidate created from the approved insight.

10. **Generate or show the draft.**
    Show the draft as a **candidate** ("No publicar hasta aprobar"). "Nothing auto-publishes."

11. **Save memory.**
    Save a reviewed conclusion as memory, labeled "contexto, no evidencia".

12. **Show usage.**
    Open the Usage Ledger. "Zero-cost beta; reserve→settle stays idempotent; expensive
    actions are accounted for."

13. **Export the decision report.**
    Show the Decision Report for the run — redacted, workspace-scoped, **no public share
    link** (private export only).

14. **Explain what is pilot-ready.**
    "Installable on WordPress, activates cleanly, migrations run, every admin screen loads
    with no errors and no secret leakage, and the full evidence→review→report loop works on
    the manual path."

15. **Explain what is NOT yet production-ready.**
    "Real provider integrations, direct publishing, full billing, multi-LLM orchestration,
    predictive trends, and real-time dashboards are deliberately out of scope. This is a
    controlled private pilot, not a production launch."
