/**
 * Platform-owned footer. PLATFORM RULE: it appears on every tenant page and cannot be
 * changed, hidden or restyled by a training provider. It takes no props on purpose —
 * tenant branding must never reach this component. Tests assert the exact text and link.
 */
export const MCS_FOOTER = {
  prefix: 'Developed and Maintained by',
  company: 'Mayura Consultancy Services',
  url: 'https://www.mayuraconsultancy.com',
} as const;

export function PlatformFooter() {
  return (
    <footer
      data-platform-footer=""
      role="contentinfo"
      style={{
        display: 'block',
        padding: '12px 16px',
        textAlign: 'center',
        fontSize: '12px',
        lineHeight: 1.5,
        color: '#6B7090',
        background: 'transparent',
        visibility: 'visible',
        opacity: 1,
      }}
    >
      {MCS_FOOTER.prefix}{' '}
      <a
        href={MCS_FOOTER.url}
        target="_blank"
        rel="noopener"
        style={{ color: '#4B45D6', fontWeight: 600, textDecoration: 'none' }}
      >
        {MCS_FOOTER.company}
      </a>
    </footer>
  );
}
