# Project TODO & Backlog

## White-Labeling & Customization
- [ ] **Dynamic Logo Path Overrides (`ORG_LOGO_PATH`)**
  - Safely define `$logoUrl = defined('ORG_LOGO_PATH') ? ORG_LOGO_PATH : 'assets/DCW_logo.png';` in helper functions to ensure zero breaking changes when server `.env` is unpopulated.
  - Rename `assets/DCW_logo.png` to generic `assets/logo.png` with backward-compatible alias.
  - Update `WHITE_LABELING.md` and `.env.example` with documentation.
