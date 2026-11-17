/** A role as the access picker needs it: `name` null means a built-in key, named by translation. */
export interface RoleOption { key: string, name: string | null }

/** A unit as the access picker needs it, flat, parents before children. */
export interface UnitOption { id: string, name: string, depth: number, archived: boolean }
