# Future Island — Private Pilot Operator Guide

For pilot operators. Future Island is a **cultural-tech marketing intelligence
workspace**. It is not an AI writer, a scraper, a scheduler, or a chatbot. The value is:

> evidence-backed interpretation → structured briefing → reviewable output → reusable
> decision memory.

You are the **review layer**. The system proposes; you decide what becomes trusted.

## 1. Mental model

```text
Signals (public/manual/provider) -> Evidence -> Insights -> Briefs
  -> Drafts/Assets -> Review decisions -> Memory -> Usage -> Decision Reports
```

- **Evidence first, generation second.** Generation stays blocked until an insight is
  reviewed.
- **Memory is context, not evidence.** Approved memory informs future work but never
  counts as proof on its own.
- **Nothing auto-publishes.** Drafts are candidates until a human approves them.

## 2. Where to work

| Screen | What it's for |
|---|---|
| Command Room | Start here: command canvas, playbook launcher, evidence drawer, run timeline. |
| Brand & Market X-Ray | Turn brand context + reference URLs into a canonical Run (no crawling). |
| Social Intelligence results | Read normalized signals as readable cards. |
| Object Flow | See lifecycle status (Fuente capturada → Señales → Brief candidato …). |
| Insight detail | Read Observed vs Inferred, with confidence and risk. |
| Insight Review Queue | Approve / reject / request revision — this is the learning step. |
| Intelligence Map | Graph + lineage list: Run → Insight → Brief → Memory. |
| Provider Ingestions Ledger | Audit provider rows (accepted/partial/rejected), redacted. |
| Run Timeline | Diagnose a run: what happened, why it's pending/partial. |
| Decision Report | The redacted, evidence-backed output you can act on. |
| Operator QA | The staging validation checklist (simulator / approved orchestrator). |

## 3. The pilot loop (do this end to end)

1. **Open Command Room.** Pick a playbook (start with Brand & Market X-Ray).
2. **Create a Run.** Add brand context + reference URLs. This writes a canonical Run;
   no external crawl or provider call is required.
3. **Review signals.** Open Social Intelligence results / Object Flow. Confirm cards are
   readable and statuses make sense.
4. **Read the insight.** In Insight detail, separate **Observado** (what was seen) from
   **Inferido** (what it implies). Note the confidence (alta/media/baja) and risk.
5. **Decide.** In the Review Queue, approve / reject / request revision. Generation
   unlocks only after an insight is approved.
6. **Brief → Draft.** Convert an approved insight into a brief candidate, then a draft.
   Drafts are candidates, never auto-published.
7. **Memory.** Save useful, reviewed conclusions as memory — labeled as context.
8. **Decision Report.** Confirm it renders from real lineage and is redacted.

## 4. Reading disabled buttons

Strategic actions stay disabled until their requirement is met, and each shows why:

- *Requiere insight aprobado.* — approve the insight first.
- *Requiere workspace activo.* — select/activate a workspace.
- *Disponible después de validar la ejecución.* — validate the run first.
- *Requiere brief aprobado.* — approve the brief first.
- *Memoria no conectada todavía.* — memory surface not wired for this object yet.

A disabled button is not a bug — it is the evidence gate doing its job.

## 5. Credentials (what works without keys)

You can run a real pilot **without** OpenAI or Apify:

- **Without any keys:** manual workflows, local records, all review screens, reports,
  and the **signed callback simulator** all work.
- **OpenAI key:** only needed for AI analysis / generation.
- **Apify token:** only needed for provider-dispatched social/search runs.

Missing keys never make the product unusable — they only gate those two capabilities.

## 6. What to report (and how it helps)

Log anything that looks wrong, with a screenshot and the screen name:

- Layout: text breaking character-by-character, mid-word splits, overlap, cut-off rows.
- Copy: English where Spanish is expected on a visible label.
- Logic: a disabled action with no reason, or an action enabled before its requirement.
- Trust: any token/secret/signature/raw payload visible anywhere (report immediately).

Your notes feed the next bugfix cycle directly — be specific (screen, viewport, steps).

## 7. Boundaries during pilot

- No direct publishing or scheduling.
- No reliance on memory as proof.
- Treat all AI/provider output as candidate until you approve it.
