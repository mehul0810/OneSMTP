# Aculect Mail Design System Adoption

## Source of truth

Use the [WordPress Design System community file](https://www.figma.com/community/file/1436359662053949167/wordpress-design-system) for component intent, interaction patterns, states, and layout guidance. Use [`DESIGN.md`](../../DESIGN.md) for Aculect Mail’s product posture, Aculect tokens, information architecture, and copy rules.

## Package policy

When a client-side interface is introduced, prefer the smallest set of official WordPress packages that covers the interaction:

- `@wordpress/components` for controls and layout primitives.
- `@wordpress/element` for rendering and lifecycle behavior.
- `@wordpress/i18n` for translatable client-side strings.
- `@wordpress/icons` for accessible WordPress iconography.
- `@wordpress/dataviews` for admin listings with search, sorting, filters, layout controls, and pagination. Plugin builds import the WordPress bundle from `@wordpress/dataviews/wp`.
- Heroicons outline paths for Aculect Mail-specific product iconography via the shared PHP helper.
- `@wordpress/data` only for shared state that cannot remain local to a component or server-rendered request.

Do not add a competing component system, icon library, or ad hoc design-token layer. If a required component is not available, document the gap and keep the fallback visually and behaviorally aligned with WordPress admin conventions.

## Adoption strategy

Aculect Mail currently has a PHP-rendered admin surface with a small JavaScript workspace controller. Keep that architecture stable while introducing the design system in bounded areas:

1. Use WordPress core markup and classes for server-rendered forms, tables, notices, and navigation.
2. Use `@wordpress/components` for new interactive islands or a deliberately migrated screen.
3. Keep data permissions, nonces, REST contracts, and server-side validation independent of the rendering layer.
4. Do not migrate a screen only for visual novelty; migrate when component behavior, accessibility, or interaction complexity justifies it.
5. Remove obsolete custom styles and dependencies after a migrated surface has equivalent test coverage.

## Visual contract

Apply the Aculect tokens in [`DESIGN.md`](../../DESIGN.md) as a restrained layer over WordPress patterns. Primary actions, focus states, status badges, and links must remain legible in default, hover, focus, disabled, warning, and error states. Never encode meaning through color alone.

## Figma implementation evidence

For a Figma-driven implementation, record the exact frame or node URL, capture the reference screenshot, identify the WordPress component equivalents, and verify the rendered result at desktop and narrow admin widths. If the Figma source cannot be fetched, do not claim pixel-level parity; use the documented WordPress and Aculect contracts and record the limitation.
