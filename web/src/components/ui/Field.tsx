import { forwardRef, useId, type InputHTMLAttributes, type ReactNode } from 'react';
import { cx } from './cx';

interface FieldProps extends InputHTMLAttributes<HTMLInputElement> {
  label: string;
  hint?: ReactNode;
  error?: string;
  trailing?: ReactNode;
}

export const inputClass =
  'h-11 w-full rounded-xl border border-line bg-surface px-3.5 text-[15px] text-ink placeholder:text-muted/70 shadow-xs transition focus:border-brand focus:outline-none focus:ring-4 focus:ring-[var(--brand-ring)]';

export const Field = forwardRef<HTMLInputElement, FieldProps>(function Field(
  { label, hint, error, trailing, className, id, ...rest },
  ref,
) {
  const auto = useId();
  const fieldId = id ?? auto;
  const describedBy = error ? `${fieldId}-error` : hint ? `${fieldId}-hint` : undefined;

  return (
    <div className={className}>
      <div className="mb-1.5 flex items-center justify-between">
        <label htmlFor={fieldId} className="text-[13px] font-semibold text-ink">
          {label}
        </label>
        {trailing}
      </div>
      <input
        ref={ref}
        id={fieldId}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy}
        className={cx(inputClass, error && 'border-[#C92A2F] focus:border-[#C92A2F] focus:ring-[#C92A2F]/15')}
        {...rest}
      />
      {error ? (
        <p id={`${fieldId}-error`} className="mt-1.5 text-[13px] font-medium text-[#C92A2F]" role="alert">
          {error}
        </p>
      ) : hint ? (
        <p id={`${fieldId}-hint`} className="mt-1.5 text-[13px] text-muted">
          {hint}
        </p>
      ) : null}
    </div>
  );
});
