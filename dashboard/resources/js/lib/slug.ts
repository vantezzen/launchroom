/**
 * `App\Support\Slug::from` for live suggestions while typing; the server
 * re-slugs on save. tests/Fixtures/slug-vectors.json keeps both equal.
 */
export function slugFrom(name: string, fallback = 'app'): string {
    const slug = name
        .replace(/[^A-Za-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .toLowerCase()
        .slice(0, 63)
        .replace(/^-+|-+$/g, '');

    return slug === '' ? fallback : slug;
}
