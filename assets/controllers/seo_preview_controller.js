import { Controller } from '@hotwired/stimulus';

const TITLE_LIMIT = 60;
const DESCRIPTION_LIMIT = 155;

/*
 * Shows how the page will roughly look in search results while the SEO
 * fields are being edited. Empty fields fall back to what the website uses
 * in that case: the title of the content, and its summary or the site tagline.
 */
export default class extends Controller {
    static targets = ['title', 'description', 'url', 'titleHint', 'descriptionHint'];
    static values = {
        siteName: String,
        baseUrl: String,
        fallbackDescription: String,
        tooLong: String,
        length: String,
    };

    connect() {
        this.form = this.element.closest('form');
        this.update = this.update.bind(this);
        this.form.addEventListener('input', this.update);
        // The editor fills fields in when it restores a backup.
        this.form.addEventListener('editor:restored', this.update);
        this.update();
    }

    disconnect() {
        this.form.removeEventListener('input', this.update);
        this.form.removeEventListener('editor:restored', this.update);
    }

    field(suffix) {
        return this.form.querySelector(`[name$="[${suffix}]"]`);
    }

    update() {
        const seoTitle = this.field('seoTitle')?.value.trim() ?? '';
        const title = seoTitle || this.field('title')?.value.trim() || '';
        const fullTitle = title && title !== this.siteNameValue ? `${title} – ${this.siteNameValue}` : this.siteNameValue;
        const description = this.field('metaDescription')?.value.trim()
            || this.field('excerpt')?.value.trim()
            || this.fallbackDescriptionValue;
        const slug = this.field('slug')?.value.trim() ?? '';

        this.titleTarget.textContent = this.shorten(fullTitle, TITLE_LIMIT + 5);
        this.descriptionTarget.textContent = this.shorten(description, DESCRIPTION_LIMIT + 5);
        this.urlTarget.textContent = slug ? `${this.baseUrlValue}${slug}` : this.baseUrlValue;

        this.hint(this.titleHintTarget, fullTitle.length, TITLE_LIMIT);
        this.hint(this.descriptionHintTarget, description.length, DESCRIPTION_LIMIT);
    }

    shorten(text, limit) {
        return text.length > limit ? `${text.slice(0, limit).trimEnd()} …` : text;
    }

    hint(target, length, limit) {
        const tooLong = length > limit;
        target.textContent = `${this.lengthValue.replace('%count%', String(length)).replace('%limit%', String(limit))}${tooLong ? ` – ${this.tooLongValue}` : ''}`;
        target.classList.toggle('seo-preview__hint--warning', tooLong);
    }
}
