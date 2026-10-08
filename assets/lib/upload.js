/**
 * Uploads image files one after the other to the media library.
 *
 * Calls onResult for every file with either { file, media } or { file, error }.
 * Resolves with the list of successfully created media items.
 */
export async function uploadFiles(files, { url, csrf, onResult = () => {}, fallbackError = 'Upload failed.' }) {
    const uploaded = [];

    for (const file of files) {
        const body = new FormData();
        body.append('file', file);

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrf, Accept: 'application/json' },
                body,
            });
            const result = await response.json().catch(() => ({}));

            if (response.ok && result.media) {
                uploaded.push(result.media);
                onResult({ file, media: result.media });
            } else {
                onResult({ file, error: result.error ?? fallbackError });
            }
        } catch {
            onResult({ file, error: fallbackError });
        }
    }

    return uploaded;
}

/** Picks the image files out of a drop or file input event. */
export function imageFiles(fileList) {
    return [...(fileList ?? [])].filter((file) => file.type.startsWith('image/'));
}
