# Deep Trend Finder v0.3.35 Live Actor + GPT QA

## Scope
This pass focused on:
- confirming recent live Apify actor results after v0.3.34
- hardening the ChatGPT/OpenAI planner and final synthesis bridge
- preventing invalid or incomplete model outputs from being treated as valid analysis

## Live actor evidence used
Recent Apify runs confirmed:
- Google Trends keyword mode completed and produced a keyword timeline dataset for `marketing` in Spain.
- Instagram official scraper direct hashtag URL mode completed and produced real post rows.
- Google News completed and produced article rows.
- Reddit and TikTok had already been confirmed in prior live QA passes.

## New bug found
The Google Trends live keyword output used nested `timeline_data`:

```json
{
  "keyword": "marketing",
  "geo": "ES",
  "timeline_data": {
    "marketing": {
      "2026-05-05": 100
    },
    "isPartial": {
      "2026-05-26": true
    }
  }
}
```

v0.3.34 already added timeline support, but this pass confirmed the shape against the live dataset and kept it covered by regression tests.

## GPT / OpenAI request and analysis hardening
A real OpenAI API request was not executed because no secure runtime `OPENAI_API_KEY` was available after the previous pasted key was treated as compromised. The OpenAI setup flow was opened for secure key creation. Until a new key is stored securely in the site/runtime, live GPT calls must not be executed from chat.

The plugin-side bridge was deeply hardened for real OpenAI response behavior:
- Chat Completions `message.content` as a string
- Chat Completions `message.content` as content blocks
- Responses API `output_text`
- Responses API `output[].content[].text`
- Markdown fenced JSON
- Provider `error` objects
- `incomplete`, `failed`, `cancelled` statuses
- model `refusal` blocks

## Fixes shipped
- Provider errors now become safe diagnostics and do not pass planner validation.
- API keys are redacted from OpenAI error diagnostics.
- Incomplete OpenAI responses are not treated as usable plans or external synthesis.
- Model refusal blocks are diagnosed rather than parsed as empty plans.
- Final synthesis bridge now parses array content blocks and rejects provider error/incomplete payloads.

## Local QA
- PHP lint: 70 files passed.
- JS syntax check: passed.
- PHP regression files: 40 passed.
- Latest cumulative regression: 397 / 397 passed.
