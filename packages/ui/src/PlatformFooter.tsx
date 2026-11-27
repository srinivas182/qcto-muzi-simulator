import { MCS_FOOTER } from '@simuwork/core';

export { MCS_FOOTER };

/**
 * Platform-owned footer. PLATFORM RULE: it appears on every tenant page and cannot be
 * changed, hidden or restyled by a training provider. It takes no props on purpose —
 * tenant branding must never reach this component. Tests assert the exact text and link.
 *
 * Colours come only from platform variables (--sw-footer-*), never from the tenant brand layer,
 * so the credit stays readable in light and dark mode. The wording is a legal credit and is
 * not translated.
 */
export function PlatformFooter() {
  return (
    <footer
      data-platform-footer=""
      role="contentinfo"
      lang="en"
      style={{
        display: 'block',
        padding: '14px 16px',
        textAlign: 'center',
        fontSize: '12px',
        lineHeight: 1.5,
        color: 'var(--sw-footer-fg, #5F6483)',
        background: 'transparent',
        visibility: 'visible',
        opacity: 1,
        position: 'relative',
        zIndex: 1,
      }}
    >
      {MCS_FOOTER.prefix}{' '}
      <a
        href={MCS_FOOTER.url}
        target="_blank"
        rel="noopener"
        style={{ color: 'var(--sw-footer-link, #4B45D6)', fontWeight: 600, textDecoration: 'none' }}
      >
        {MCS_FOOTER.company}
      </a>
    </footer>
  );
}
