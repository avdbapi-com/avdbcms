import { useState, useRef } from 'preact/hooks';
import {formatSizeUnits} from "@/functions/size";
import { useTranslation } from "react-i18next";
import {ChangeEvent} from "react";

type Props = {
    configendpoint: string,
    completeendpoint: string,
    maxsize: number,
    maxfilename?: number,
    allowedformats?: string
}

function generateUUID(): string {
    return 'u18_' + Date.now() + '_' + Math.random().toString(36).substring(2, 10);
}

const DEFAULT_CHUNK_SIZE = 90 * 1000 * 1000;

type Upload18Identifiers = {
    did: string;
    vid: string;
};

async function captureThumbnailsFromFile(file: File, count = 3): Promise<string[]> {
    return new Promise((resolve) => {
        let objectUrl: string | null = null;
        try {
            objectUrl = URL.createObjectURL(file);
        } catch {
            resolve([]);
            return;
        }

        const video = document.createElement('video');
        video.preload = 'metadata';
        video.muted = true;
        video.playsInline = true;

        let isCleanedUp = false;
        const cleanup = () => {
            if (isCleanedUp) return;
            isCleanedUp = true;
            if (objectUrl) {
                try {
                    URL.revokeObjectURL(objectUrl);
                } catch {}
            }
            video.removeAttribute('src');
            video.load();
        };

        const timeoutTimer = setTimeout(() => {
            cleanup();
            resolve([]);
        }, 15000);

        video.onloadedmetadata = async () => {
            try {
                const duration = Number.isFinite(video.duration) && video.duration > 0 ? video.duration : 10;
                const positions: number[] = [];

                if (duration <= 3) {
                    positions.push(Math.max(0.1, duration * 0.2));
                    if (count > 1) positions.push(Math.max(0.2, duration * 0.5));
                    if (count > 2) positions.push(Math.max(0.3, duration * 0.8));
                } else {
                    for (let i = 1; i <= count; i++) {
                        positions.push(Math.max(0.5, Math.min(duration - 0.5, duration * (i / (count + 1)))));
                    }
                }

                const canvas = document.createElement('canvas');
                canvas.width = 640;
                canvas.height = 360;
                const ctx = canvas.getContext('2d');

                if (!ctx) {
                    clearTimeout(timeoutTimer);
                    cleanup();
                    resolve([]);
                    return;
                }

                const frames: string[] = [];

                for (const pos of positions) {
                    video.currentTime = pos;
                    await new Promise<void>((resSeek) => {
                        const onSeeked = () => {
                            video.removeEventListener('seeked', onSeeked);
                            resSeek();
                        };
                        const seekTimeout = setTimeout(() => {
                            video.removeEventListener('seeked', onSeeked);
                            resSeek();
                        }, 2500);
                        video.addEventListener('seeked', onSeeked);
                    });

                    ctx.fillStyle = '#000000';
                    ctx.fillRect(0, 0, canvas.width, canvas.height);

                    const vw = video.videoWidth || canvas.width;
                    const vh = video.videoHeight || canvas.height;
                    const scale = Math.min(canvas.width / vw, canvas.height / vh);
                    const dw = vw * scale;
                    const dh = vh * scale;
                    const dx = (canvas.width - dw) / 2;
                    const dy = (canvas.height - dh) / 2;

                    ctx.drawImage(video, dx, dy, dw, dh);
                    const dataUrl = canvas.toDataURL('image/jpeg', 0.85);
                    if (dataUrl && dataUrl.startsWith('data:image/jpeg;base64,')) {
                        frames.push(dataUrl);
                    }
                }

                clearTimeout(timeoutTimer);
                cleanup();
                resolve(frames);
            } catch {
                clearTimeout(timeoutTimer);
                cleanup();
                resolve([]);
            }
        };

        video.onerror = () => {
            clearTimeout(timeoutTimer);
            cleanup();
            resolve([]);
        };

        video.src = objectUrl;
    });
}

export function VideoUpload ({configendpoint, completeendpoint, maxsize, maxfilename = 100, allowedformats = ''} : Props) {

    const { t } = useTranslation();
    const allowedExtensions = String(allowedformats)
        .split(',')
        .map((item) => item.trim().toLowerCase().replace(/^\./, ''))
        .filter(Boolean);
    const accept = allowedExtensions.length
        ? allowedExtensions.map((extension) => `.${extension}`).join(',')
        : 'video/*';

    const [loading, setLoading] = useState<boolean>(false);
    const [error, setError] = useState<string>('');
    const [progress, setProgress] = useState<number>(0);
    const [statusText, setStatusText] = useState<string>('');
    const [isDrag, setDrag] = useState<boolean>(false);

    const abortRef = useRef<boolean>(false);
    const input = useRef<HTMLInputElement>(null);

    const change = async (event: ChangeEvent<HTMLInputElement>) => {

        setError('');

        const file = event.currentTarget.files?.[0];
        event.currentTarget.value = '';

        if (!file) return;

        const extension = file.name.split('.').pop()?.toLowerCase() || '';
        if (allowedExtensions.length && !allowedExtensions.includes(extension)) {
            setError(
                t('The video format is not allowed.')
            )
            return;
        }

        if (file.size > maxsize) {
            setError(
                t('Your file is too large ({size} MB) The uploading file should not exceed {maxSize} MB', {
                    size: formatSizeUnits(file.size),
                    maxSize: formatSizeUnits(maxsize)
                })
            )
            return;
        }

        const fileBaseName = file.name.replace(/\.[^.]+$/, '');
        const maxNameLen = parseInt(String(maxfilename), 10) || 100;
        if (fileBaseName.length > maxNameLen) {
            setError(
                t('Your file name does not exceed {max} characters', {
                    max: maxNameLen.toString()
                })
            )
            return;
        }

        setLoading(true);
        setProgress(0);
        abortRef.current = false;

        const capturedThumbsPromise = captureThumbnailsFromFile(file, 3);

        try {
            setStatusText(t('Preparing upload...'));

            const token = document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement;
            const csrfToken = token?.getAttribute('content') || '';

            const configRes = await fetch(configendpoint, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken,
                },
                credentials: 'same-origin',
            });

            if (!configRes.ok) {
                const data = await configRes.json().catch(() => ({}));
                throw new Error(data?.error || data?.message || `Config failed: ${configRes.status}`);
            }

            const config = await configRes.json();

            if (config?.upload_mode === 'server') {
                setStatusText(t('Direct upload unavailable, uploading through server...'));
                const fallback = await uploadToServer(file, config, csrfToken);
                const capturedThumbnails = await capturedThumbsPromise.catch(() => []);
                if (capturedThumbnails.length > 0 && fallback?.video_id) {
                    try {
                        await fetch(`/api/videos/${fallback.video_id}/generate-thumbnails`, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'Content-Type': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': csrfToken,
                            },
                            credentials: 'same-origin',
                            body: JSON.stringify({ thumbnails: capturedThumbnails }),
                        });
                    } catch {}
                }
                setProgress(100);
                window.location.replace(fallback.route);
                return;
            }

            setStatusText(t('Uploading directly to Upload18...'));
            let uploadIds: Upload18Identifiers;

            try {
                uploadIds = await uploadToUpload18(file, config);
            } catch (uploadError: any) {
                if (!shouldUseServerFallback(uploadError, config)) {
                    throw new Error(
                        t('Upload18 direct upload failed: {message}', {
                            message: uploadError?.message || 'Network error',
                        })
                    );
                }

                setStatusText(t('Direct upload unavailable, uploading through server...'));
                const fallback = await uploadToServer(file, config, csrfToken);
                const capturedThumbnails = await capturedThumbsPromise.catch(() => []);
                if (capturedThumbnails.length > 0 && fallback?.video_id) {
                    try {
                        await fetch(`/api/videos/${fallback.video_id}/generate-thumbnails`, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'Content-Type': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': csrfToken,
                            },
                            credentials: 'same-origin',
                            body: JSON.stringify({ thumbnails: capturedThumbnails }),
                        });
                    } catch {}
                }
                setProgress(100);
                window.location.replace(fallback.route);
                return;
            }

            if (abortRef.current) return;

            setStatusText(t('Processing video on Upload18...'));
            setProgress(92);

            const uploadStatus = await pollForEmbedCode(uploadIds, config);

            if (abortRef.current) return;

            setStatusText(t('Finalizing metadata...'));
            setProgress(98);

            const capturedThumbnails = await capturedThumbsPromise.catch(() => []);

            const completeRes = await fetch(completeendpoint, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken,
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    did: uploadIds.did,
                    vid: uploadStatus.vid || uploadIds.vid,
                    filename: file.name,
                    size: file.size,
                    duration: uploadStatus.duration,
                    duration_seconds: uploadStatus.duration_seconds,
                    thumbnails: capturedThumbnails.length > 0 ? capturedThumbnails : undefined,
                }),
            });

            if (!completeRes.ok) {
                const data = await completeRes.json().catch(() => ({}));
                throw new Error(data?.message || `Complete failed: ${completeRes.status}`);
            }

            const data = await completeRes.json();
            setProgress(100);

            window.location.replace(data.route);

        } catch (err: any) {
            if (!abortRef.current) {
                setLoading(false);
                setError(err?.message || 'Upload failed. Please try again.');
            }
        }
    }

    async function pollForEmbedCode(uploadIds: Upload18Identifiers, config: any): Promise<any> {
        const maxWait = 600000;
        const pollInterval = 10000;
        let elapsed = 0;
        const statusUrl = config.status_url || (configendpoint ? configendpoint.replace(/\/config\/?$/, '/status') : '/api/videos/upload18/status');

        while (elapsed < maxWait) {
            if (abortRef.current) throw new Error('Upload cancelled');

            const waitSec = Math.round(elapsed / 1000);
            setStatusText(t('Processing video on Upload18... ({seconds}s)', { seconds: waitSec.toString() }));

            try {
                const url = new URL(statusUrl, window.location.origin);
                url.searchParams.set('did', uploadIds.did);
                if (uploadIds.vid) url.searchParams.set('vid', uploadIds.vid);

                const res = await fetch(url.toString(), {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });

                if (res.ok) {
                    const data = await res.json();

                    if (data?.ready && data?.vid) {
                        return data;
                    }
                }
            } catch (e) {
            }

            await sleep(pollInterval);
            elapsed += pollInterval;
        }

        throw new Error('Video processing timed out. Please try again.');
    }

    async function uploadToUpload18(file: File, config: any): Promise<Upload18Identifiers> {
        const { apikey, upload_url, cid, mycid, fid } = config;
        const chunkSize = configuredChunkSize(config.chunk_size);

        if (file.size <= chunkSize) {
            const MAX_RETRIES = 30;
            const WAIT_MS = 20000;

            for (let attempt = 0; attempt < MAX_RETRIES; attempt++) {
                const formData = new FormData();
                formData.append('apikey', apikey);
                formData.append('cid', cid);
                formData.append('mycid', mycid || '0');
                formData.append('fid', fid);
                formData.append('video', file, file.name);

                const result = await uploadWithProgress(upload_url, formData, (pct) => {
                    setProgress(Math.round(pct * 90));
                });

                const msg = ((result?.msg || result?.message || '') + '').toLowerCase();
                const status = ((result?.status || '') + '').toLowerCase();

                if (status === 'success' || result?.did) {
                    return handleUploadResponse(result);
                }

                if (msg.includes('wait') || msg.includes('processing') ||
                    msg.includes('upload request') || msg.includes('error on uploaded file') ||
                    status === 'please wait') {
                    setStatusText(t('Server busy, waiting... (attempt {n})', { n: (attempt + 1).toString() }));
                    await sleep(WAIT_MS);
                    continue;
                }

                return handleUploadResponse(result);
            }

            throw new Error('Upload18: Max retries exceeded (server busy)');
        } else {
            return await uploadChunked(file, config, chunkSize);
        }
    }

    async function uploadChunked(file: File, config: any, chunkSize: number): Promise<Upload18Identifiers> {
        const { apikey, upload_url, cid, mycid, fid } = config;
        const dzuuid = generateUUID();
        const totalChunks = Math.ceil(file.size / chunkSize);
        let lastResult: any = null;

        for (let i = 0; i < totalChunks; i++) {
            if (abortRef.current) throw new Error('Upload cancelled');

            const start = i * chunkSize;
            const end = Math.min(start + chunkSize, file.size);
            const chunk = file.slice(start, end);
            const chunkActualSize = end - start;

            setStatusText(t('Uploading Upload18 chunk {current} of {total}...', {
                current: (i + 1).toString(),
                total: totalChunks.toString(),
            }));

            const MAX_RETRIES = 30;
            const WAIT_MS = 20000;
            let result: any = null;

            for (let attempt = 0; attempt < MAX_RETRIES; attempt++) {
                const formData = new FormData();
                formData.append('apikey', apikey);
                formData.append('cid', cid);
                formData.append('mycid', mycid || '0');
                formData.append('fid', fid);
                formData.append('dzuuid', dzuuid);
                formData.append('dzchunkindex', i.toString());
                formData.append('dztotalchunkcount', totalChunks.toString());
                formData.append('dzchunksize', chunkActualSize.toString());
                formData.append('dztotalfilesize', file.size.toString());
                formData.append('dzchunkbyteoffset', start.toString());
                formData.append('video', chunk, file.name);

                result = await uploadWithProgress(upload_url, formData, (pct) => {
                    const chunkProgress = (i + pct) / totalChunks;
                    setProgress(Math.round(chunkProgress * 90));
                });

                const msg = ((result?.msg || result?.message || '') + '').toLowerCase();
                const status = ((result?.status || '') + '').toLowerCase();

                if (status === 'success') break;

                if (msg.includes('wait') || msg.includes('processing') ||
                    msg.includes('upload request') || msg.includes('error on uploaded file') ||
                    status === 'please wait') {
                    setStatusText(t('Server busy, waiting... (attempt {n})', { n: (attempt + 1).toString() }));
                    await sleep(WAIT_MS);
                    continue;
                }

                break;
            }

            if (!result || result.status === 'error') {
                const errMsg = result?.msg || result?.message || 'Chunk upload failed';
                throw new Error(errMsg);
            }

            lastResult = result;
        }

        return handleUploadResponse(lastResult);
    }

    async function uploadToServer(file: File, config: any, csrfToken: string): Promise<any> {
        if (!config.server_chunk_url) {
            throw new Error('Server upload endpoint is not configured');
        }

        const chunkSize = configuredChunkSize(config.server_chunk_size || config.chunk_size);
        const totalChunks = Math.ceil(file.size / chunkSize);
        const identifier = generateUUID();
        let lastResult: any = null;

        for (let i = 0; i < totalChunks; i++) {
            if (abortRef.current) throw new Error('Upload cancelled');

            const start = i * chunkSize;
            const end = Math.min(start + chunkSize, file.size);
            const chunk = file.slice(start, end);
            const formData = new FormData();

            formData.append('resumableFilename', file.name);
            formData.append('resumableTotalSize', file.size.toString());
            formData.append('resumableIdentifier', identifier);
            formData.append('resumableChunkNumber', (i + 1).toString());
            formData.append('resumableTotalChunks', totalChunks.toString());
            formData.append('file', chunk, file.name);

            setStatusText(t('Uploading chunk {current} of {total}...', {
                current: (i + 1).toString(),
                total: totalChunks.toString(),
            }));

            lastResult = await uploadWithProgress(
                config.server_chunk_url,
                formData,
                (pct) => {
                    const chunkProgress = (i + pct) / totalChunks;
                    setProgress(Math.round(chunkProgress * 95));
                },
                {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken,
                },
                true,
            );
        }

        if (!lastResult?.complete || !lastResult?.route) {
            throw new Error(lastResult?.message || 'Server upload did not complete');
        }

        return lastResult;
    }

    function shouldUseServerFallback(error: any, config: any): boolean {
        if (!config?.allow_server_fallback || !config?.server_chunk_url) return false;

        const message = String(error?.message || '').toLowerCase();

        return message.includes('network error')
            || message.includes('invalid response')
            || message.includes('upload timed out')
            || message.includes('http 0');
    }

    function configuredChunkSize(value: any): number {
        const size = Number.parseInt(String(value || ''), 10);

        return Number.isFinite(size) && size > 0 ? size : DEFAULT_CHUNK_SIZE;
    }

    function handleUploadResponse(result: any): Upload18Identifiers {
        if (!result) throw new Error('No response from Upload18');
        const errorPayload = findUpload18Error(result);
        if (errorPayload) throw new Error(errorPayload.msg || errorPayload.message || 'Upload18 error');

        const did = extractUpload18Id(result, ['did', 'id']);
        const vid = extractUpload18Id(result, ['vid']) || '';

        if (did) return { did, vid };
        throw new Error(`Upload completed but no video ID received (${describeResponseShape(result)})`);
    }

    function extractUpload18Id(value: any, keys: string[], depth = 0): string | null {
        if (depth > 6 || value === null || value === undefined) return null;

        const scalar = normalizeUpload18Id(value);
        if (scalar) return scalar;
        if (typeof value !== 'object') return null;

        for (const key of keys) {
            if (Object.prototype.hasOwnProperty.call(value, key)) {
                const found = extractUpload18Id(value[key], keys, depth + 1);
                if (found) return found;
            }
        }

        for (const key of ['data', 'result', 'video', 'file', 'response']) {
            if (Object.prototype.hasOwnProperty.call(value, key)) {
                const found = extractUpload18Id(value[key], keys, depth + 1);
                if (found) return found;
            }
        }

        if (Array.isArray(value)) {
            for (const item of value) {
                const found = extractUpload18Id(item, keys, depth + 1);
                if (found) return found;
            }
        }

        return null;
    }

    function findUpload18Error(value: any, depth = 0): any | null {
        if (depth > 6 || !value || typeof value !== 'object') return null;
        if (String(value.status || '').toLowerCase() === 'error') return value;

        for (const key of ['data', 'result', 'response']) {
            const found = findUpload18Error(value[key], depth + 1);
            if (found) return found;
        }

        return null;
    }

    function normalizeUpload18Id(value: any): string | null {
        if (typeof value === 'number') {
            return Number.isSafeInteger(value) && value >= 0 ? value.toString() : null;
        }

        if (typeof value !== 'string') return null;

        const normalized = value.trim();
        return normalized && /^[A-Za-z0-9_-]+$/.test(normalized) ? normalized : null;
    }

    function describeResponseShape(value: any): string {
        if (Array.isArray(value)) return `array:${value.length}`;
        if (!value || typeof value !== 'object') return typeof value;

        return Object.entries(value)
            .slice(0, 12)
            .map(([key, item]) => `${key}:${Array.isArray(item) ? 'array' : typeof item}`)
            .join(',') || 'empty object';
    }

    function uploadWithProgress(
        url: string,
        formData: FormData,
        onProgress: (pct: number) => void,
        headers: Record<string, string> = {},
        withCredentials = false,
    ): Promise<any> {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', url, true);
            xhr.withCredentials = withCredentials;

            for (const [key, value] of Object.entries(headers)) {
                xhr.setRequestHeader(key, value);
            }

            xhr.upload.onprogress = (e) => {
                if (e.lengthComputable) onProgress(e.loaded / e.total);
            };

            xhr.onload = () => {
                let data: any = null;

                try {
                    data = JSON.parse(xhr.responseText);
                } catch {
                    reject(new Error(`Invalid response: ${xhr.status}`));
                    return;
                }

                if (xhr.status < 200 || xhr.status >= 300) {
                    reject(new Error(data?.message || data?.error || `HTTP ${xhr.status}`));
                    return;
                }

                resolve(data);
            };

            xhr.onerror = () => reject(new Error('Network error'));
            xhr.ontimeout = () => reject(new Error('Upload timed out'));
            xhr.timeout = 600000;

            xhr.send(formData);
        });
    }

    function sleep(ms: number): Promise<void> {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    const cancel = () => {
        abortRef.current = true;
        setLoading(false);
        setProgress(0);
        setStatusText('');
    }

    const browse = () => {
        setError('');
        input.current?.click();
    }

    const acceptDroppedFile = (file: File) => {
        const dataTransfer = new DataTransfer();
        dataTransfer.items.add(file);

        if (input.current) {
            input.current.files = dataTransfer.files;
            change({ currentTarget: input.current } as ChangeEvent<HTMLInputElement>);
        }
    }

    return (
        <div className={"video-upload-state" + (loading && !error ? ' is-uploading' : '')}>
            {
                (!loading || error) &&
                <div
                    className={"video-upload-dropzone" + (isDrag ? ' is-drag' : '')}
                    onClick={browse}
                    onKeyDown={(event: any) => {
                        if (event.key === 'Enter' || event.key === ' ') {
                            event.preventDefault();
                            browse();
                        }
                    }}
                    role="button"
                    tabIndex={0}
                >
                    <input
                        id="video-upload-file"
                        type="file"
                        name="file"
                        accept={accept}
                        className="video-upload-dropzone__input"
                        ref={input}
                        onChange={change}
                    />
                    <div
                        className="video-upload-dropzone__frame"
                        onDragEnter={() => setDrag(true)}
                        onDragLeave={() => setDrag(false)}
                        onDragOver={(e: any) => { e.preventDefault(); setDrag(true); }}
                        onDrop={(e: any) => {
                            e.preventDefault();
                            setDrag(false);
                            const file = e.dataTransfer?.files?.[0];
                            if (file) {
                                acceptDroppedFile(file);
                            }
                        }}
                    >
                        <div className="video-upload-orbit" aria-hidden="true">
                            <span className="video-upload-orbit__trail"></span>
                            <span className="video-upload-orbit__core">
                                <i className="fa-solid fa-cloud-arrow-up"></i>
                            </span>
                            <span className="video-upload-orbit__spark is-one"></span>
                            <span className="video-upload-orbit__spark is-two"></span>
                        </div>
                        <div className="video-upload-dropzone__copy">
                            <strong>{t('Drag and drop video file to upload')}</strong>
                            <span>{t('Your video will be private until you publish them')}</span>
                        </div>
                        <button
                            type="button"
                            className="btn btn-primary text-uppercase video-upload-dropzone__button"
                            onClick={(e) => { e.stopPropagation(); browse(); }}
                        >
                            <i className="fa-solid fa-plus"></i>
                            {t('Select file')}
                        </button>
                    </div>
                </div>

            }
            {
                error &&
                <div className="alert alert-danger video-upload-error" role="alert">
                    <i className="fa-solid fa-triangle-exclamation"></i>
                    <strong>{error}</strong>
                </div>
            }
            {
                (loading && !error) &&
                <div className="video-upload-progress-panel">
                    <div
                        className="video-upload-progress-ring"
                        role="progressbar"
                        aria-valuenow={progress}
                        aria-valuemin={0}
                        aria-valuemax={100}
                        style={{'--avd-upload-progress': `${progress * 3.6}deg`} as any}
                    >
                        <span>{progress}%</span>
                    </div>
                    <div className="video-upload-progress-copy">
                        <h2>{t('Your video is uploading ...')}</h2>
                        {statusText && <span role="status" aria-live="polite">{statusText}</span>}
                    </div>
                    <div className="alert alert-danger video-upload-warn">
                        <i className="fa-solid fa-circle-exclamation"></i>
                        {t('Dont quit or reload page please')}
                    </div>
                    <div className="progress video-upload-progress-bar">
                        <div
                            className="progress-bar progress-bar-striped progress-bar-animated"
                            style={{width: progress + '%'}}
                        />
                    </div>
                    <button className="btn btn-secondary text-uppercase" onClick={cancel}>
                        {t('Cancel Upload')}
                    </button>
                </div>
            }
        </div>
    )
}
