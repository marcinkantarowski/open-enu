import { type ClassValue, clsx } from 'clsx'
import { twMerge } from 'tailwind-merge'

/**
 * Join class lists, letting a later class replace an earlier one it conflicts with.
 *
 * `cn('px-4 bg-accent', props.class)` with `px-2` from the caller gives `px-2`, not both:
 * without the merge, which one wins depends on the order Tailwind happened to emit them.
 */
export function cn(...inputs: ClassValue[]): string {
  return twMerge(clsx(inputs))
}
