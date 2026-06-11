# LIVE ACTOR AND GPT QA — v0.3.43

Validated live actor shapes in this QA round:

- TikTok: `text`, `webVideoUrl`, `playCount`, `diggCount`, `commentCount`, `shareCount`, `collectCount`, `searchQuery`, `authorMeta.*`, `videoMeta.coverUrl`.
- Instagram: `caption`, `shortCode`, `url`, `likesCount`, `commentsCount`, `displayUrl`, `ownerUsername`, `hashtags`.
- Reddit: `title`, `body`, `communityName`, `upVotes`, `numberOfComments`, `createdAt`; can be adjacent/off-topic.
- Google News: `title`, `url`, `source`, `publishedAt`, `image`, `metadata.keyword`; publisher names can create acronym false positives.
- Google Trends: nested `timeline_data.{keyword}.{date}`.

OpenAI live call note:

- The plugin supports live OpenAI smoke test through server-side key only.
- Request-body keys are ignored and redacted.
- Pasted chat keys must be revoked and are not used by the plugin.
