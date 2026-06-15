# Accessibility and Responsive QA

## Accessibility gates

- Visible focus states.
- Semantic headings.
- Form labels.
- Inline guidance and error/safe empty states.
- Keyboard-friendly buttons and details/summary elements.
- No color-only status communication.
- Reduced-motion support.
- 36px+ admin button minimum in patched surfaces, with target 44px for future frontend polish.
- Escaped output in renderers.

## Responsive gates

Validate:

```text
320px
375px
390px
430px
768px
1024px
wide desktop
```

Expected behavior:

- Command layout collapses to a single column.
- Drawers become stacked sections.
- Provider filters collapse to one column.
- Run timeline cards stack.
- Inline action forms stack.
- Report preview remains readable.
