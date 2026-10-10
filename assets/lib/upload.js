/**
 * Uploads image files one after the other to the media library.
 *
 * Calls onProgress while a file travels ({ file, index, total, fraction } with a fraction from 0 to 1)
 * and onResult for every file with either { file, media } or { file, error }.
 * Resolves with the list of successfully created media items.
 */
export async function uploadFiles(files, { url, csrf, onResult = () => {}, onProgress = () => {}, fallbackError = 'Upload failed.' }) {
    const uploaded = [];
    const total = files.length;

    for (const [index, file] of [...files].entries()) {
        onProgress({ file, index, total, fraction: 0 });
        const result = await send(file, url, csrf, (fraction) => onProgress({ file, index, total, fraction }));

        if (result.media) {
            uploaded.push(result.media);
            onResult({ file, media: result.media });
        } else {
            onResult({ file, error: result.error ?? fallbackError });
        }
    }

    return uploaded;
}

/**
 * One file, sent with XMLHttpRequest because fetch() cannot report how much has been sent.
 */
function send(file, url, csrf, onProgress) {
    return new Promise((resolve) => {
        const body = new FormData();
        body.append('file', file);

        const request = new XMLHttpRequest();
        request.open('POST', url);
        request.setRequestHeader('X-CSRF-Token', csrf);
        request.setRequestHeader('Accept', 'application/json');
        request.upload.addEventListener('progress', (event) => {
            if (event.lengthComputable) {
                onProgress(event.loaded / event.total);
            }
        });
        request.addEventListener('load', () => {
            let result = {};
            try {
                result = JSON.parse(request.responseText);
            } catch {
                // Not JSON, e.g. an error page of the web server: the fallback message is shown.
            }
            resolve(request.status >= 200 && request.status < 300 && result.media ? { media: result.media } : { error: result.error });
        });
        request.addEventListener('error', () => resolve({}));
        request.addEventListener('abort', () => resolve({}));
        request.send(body);
    });
}

/** Picks the image files out of a drop or file input event. */
export function imageFiles(fileList) {
    return [...(fileList ?? [])].filter((file) => file.type.startsWith('image/'));
}

/**
 * Sorts dropped files into those that can be uploaded and the reasons why the others cannot,
 * before anything is sent. The server checks again.
 *
 * @param {File[]} files
 * @param {object} rules
 * @param {number} rules.maxBytes  largest accepted file
 * @param {string[]} rules.types   accepted MIME types, e.g. ['image/jpeg', 'image/png']
 * @param {{too_large: string, wrong_type: string}} rules.messages with %name% (and %limit% for the size)
 *
 * @returns {{accepted: File[], rejected: {file: File, error: string}[]}}
 */
export function checkFiles(files, { maxBytes, types, messages }) {
    const accepted = [];
    const rejected = [];
    const megabytes = String(Math.floor(maxBytes / 1024 / 1024));

    for (const file of files) {
        if (!types.includes(file.type)) {
            rejected.push({ file, error: messages.wrong_type.replaceAll('%name%', file.name) });
        } else if (file.size > maxBytes) {
            rejected.push({ file, error: messages.too_large.replaceAll('%name%', file.name).replaceAll('%limit%', megabytes) });
        } else {
            accepted.push(file);
        }
    }

    return { accepted, rejected };
}
