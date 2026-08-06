<!doctype html>
<html lang="fr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="referrer" content="no-referrer">
        <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>Envoi sécurisé de documents</title>

        <style nonce="{{ $nonce }}">
            :root {
                color-scheme: light;
                font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                color: #172033;
                background: #f3f6fb;
                font-synthesis: none;
                text-rendering: optimizeLegibility;
            }

            * {
                box-sizing: border-box;
            }

            [hidden] {
                display: none !important;
            }

            body {
                min-width: 320px;
                min-height: 100vh;
                margin: 0;
                background: linear-gradient(180deg, #eaf2ff 0, #f7f9fc 18rem, #f3f6fb 100%);
            }

            button,
            input {
                font: inherit;
            }

            button {
                touch-action: manipulation;
            }

            .shell {
                width: min(100%, 42rem);
                margin: 0 auto;
                padding: max(1rem, env(safe-area-inset-top)) 1rem max(2rem, env(safe-area-inset-bottom));
            }

            .clinic-header {
                display: flex;
                align-items: center;
                gap: 0.9rem;
                padding: 0.5rem 0.25rem 1.15rem;
            }

            .clinic-mark {
                display: grid;
                width: 3.25rem;
                height: 3.25rem;
                flex: 0 0 auto;
                place-items: center;
                border: 1px solid #bfd5ff;
                border-radius: 1rem;
                color: #0758c9;
                background: #fff;
                box-shadow: 0 0.45rem 1.4rem rgba(22, 78, 159, 0.12);
                font-size: 1.6rem;
                font-weight: 900;
            }

            .clinic-copy {
                min-width: 0;
            }

            .clinic-copy h1 {
                overflow: hidden;
                margin: 0;
                font-size: 1.06rem;
                line-height: 1.35;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .clinic-copy p {
                overflow: hidden;
                margin: 0.2rem 0 0;
                color: #60708a;
                font-size: 0.82rem;
                line-height: 1.45;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .stack {
                display: grid;
                gap: 1rem;
            }

            .card {
                border: 1px solid #d9e2ef;
                border-radius: 1.25rem;
                background: #fff;
                box-shadow: 0 0.5rem 1.7rem rgba(27, 50, 83, 0.07);
            }

            .card-body {
                padding: 1.2rem;
            }

            .status-card {
                padding: 2rem 1.35rem;
                text-align: center;
            }

            .status-symbol {
                display: grid;
                width: 3.6rem;
                height: 3.6rem;
                margin: 0 auto 1rem;
                place-items: center;
                border-radius: 50%;
                background: #eaf2ff;
                color: #0758c9;
                font-size: 1.55rem;
                font-weight: 900;
            }

            .status-warning .status-symbol {
                color: #a13a13;
                background: #fff0e8;
            }

            .status-success .status-symbol {
                color: #08754d;
                background: #e7f8f0;
            }

            .status-card h2 {
                margin: 0;
                font-size: 1.25rem;
                line-height: 1.4;
            }

            .status-card p {
                max-width: 31rem;
                margin: 0.65rem auto 0;
                color: #5c6a80;
                font-size: 0.92rem;
                line-height: 1.65;
            }

            .spinner {
                width: 1.7rem;
                height: 1.7rem;
                border: 0.2rem solid #bdd2f4;
                border-top-color: #0758c9;
                border-radius: 50%;
                animation: spin 0.8s linear infinite;
            }

            @keyframes spin {
                to {
                    transform: rotate(360deg);
                }
            }

            @media (prefers-reduced-motion: reduce) {
                .spinner {
                    animation-duration: 1.8s;
                }
            }

            .session-row {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 1rem;
            }

            .eyebrow {
                margin: 0;
                color: #26364d;
                font-size: 0.88rem;
                font-weight: 750;
            }

            .muted {
                margin: 0.25rem 0 0;
                color: #68778d;
                font-size: 0.78rem;
                line-height: 1.5;
            }

            .countdown {
                min-width: 5rem;
                padding: 0.55rem 0.7rem;
                border-radius: 99rem;
                color: #064daa;
                background: #e8f1ff;
                font-variant-numeric: tabular-nums;
                font-size: 0.9rem;
                font-weight: 800;
                text-align: center;
            }

            .section-title {
                margin: 0;
                font-size: 1rem;
                line-height: 1.4;
            }

            .picker {
                display: grid;
                width: 100%;
                min-height: 8.5rem;
                margin-top: 1rem;
                padding: 1.2rem;
                place-items: center;
                border: 2px dashed #89afe8;
                border-radius: 1rem;
                color: #0758c9;
                background: #f2f7ff;
                cursor: pointer;
                font-weight: 750;
                line-height: 1.5;
                text-align: center;
            }

            .picker:hover,
            .picker:focus-visible {
                border-color: #0758c9;
                outline: none;
                background: #e9f2ff;
            }

            .picker:disabled {
                cursor: not-allowed;
                opacity: 0.55;
            }

            .picker-icon {
                display: block;
                margin-bottom: 0.35rem;
                font-size: 1.7rem;
            }

            .file-input {
                position: absolute;
                width: 1px;
                height: 1px;
                overflow: hidden;
                clip: rect(0, 0, 0, 0);
                clip-path: inset(50%);
                white-space: nowrap;
            }

            .file-list {
                display: grid;
                gap: 0.5rem;
                margin: 0.85rem 0 0;
                padding: 0;
                list-style: none;
            }

            .file-row {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 0.75rem;
                min-width: 0;
                padding: 0.7rem 0.8rem;
                border-radius: 0.75rem;
                background: #f5f7fa;
                font-size: 0.84rem;
            }

            .file-name {
                overflow: hidden;
                min-width: 0;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .file-size {
                flex: 0 0 auto;
                color: #6a788c;
                font-size: 0.74rem;
            }

            .button {
                width: 100%;
                min-height: 3rem;
                margin-top: 1rem;
                padding: 0.7rem 1rem;
                border: 0;
                border-radius: 0.8rem;
                color: #fff;
                background: #0865d8;
                cursor: pointer;
                font-weight: 800;
            }

            .button:hover,
            .button:focus-visible {
                outline: 3px solid rgba(8, 101, 216, 0.22);
                outline-offset: 2px;
                background: #0758bd;
            }

            .button:disabled {
                cursor: not-allowed;
                opacity: 0.55;
            }

            .button-success {
                background: #0b8159;
            }

            .button-success:hover,
            .button-success:focus-visible {
                background: #096d4c;
            }

            .progress {
                height: 0.35rem;
                overflow: hidden;
                margin-top: 0.85rem;
                border-radius: 99rem;
                background: #dfe9f7;
            }

            .progress::after {
                display: block;
                width: 45%;
                height: 100%;
                border-radius: inherit;
                background: #0865d8;
                animation: progress 1.1s ease-in-out infinite alternate;
                content: "";
            }

            @keyframes progress {
                from {
                    transform: translateX(-15%);
                }

                to {
                    transform: translateX(145%);
                }
            }

            .feedback {
                margin-top: 0.85rem;
                padding: 0.75rem 0.85rem;
                border: 1px solid #f1c6ba;
                border-radius: 0.75rem;
                color: #932f17;
                background: #fff3ef;
                font-size: 0.82rem;
                line-height: 1.5;
            }

            .feedback-success {
                border-color: #a9dfc8;
                color: #086343;
                background: #effbf6;
            }

            .received-heading {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 0.75rem;
            }

            .received-count {
                padding: 0.25rem 0.55rem;
                border-radius: 99rem;
                color: #08704c;
                background: #e9f8f1;
                font-size: 0.75rem;
                font-weight: 800;
            }

            .privacy-note {
                margin: 0;
                padding: 0.25rem 0.7rem;
                color: #68778d;
                font-size: 0.75rem;
                line-height: 1.6;
                text-align: center;
            }

            @media (min-width: 42rem) {
                .shell {
                    padding-right: 1.5rem;
                    padding-left: 1.5rem;
                }

                .card-body {
                    padding: 1.4rem;
                }
            }
        </style>
    </head>
    <body>
        <main class="shell">
            <header class="clinic-header">
                <div class="clinic-mark" aria-hidden="true">+</div>
                <div class="clinic-copy">
                    <h1>{{ $clinic['name'] !== '' ? $clinic['name'] : 'MediSmart' }}</h1>
                    @if ($clinic['phone'] !== '' || $clinic['city'] !== '')
                        <p>
                            @if ($clinic['phone'] !== '')
                                {{ $clinic['phone'] }}
                            @endif
                            @if ($clinic['phone'] !== '' && $clinic['city'] !== '')
                                ·
                            @endif
                            @if ($clinic['city'] !== '')
                                {{ $clinic['city'] }}
                            @endif
                        </p>
                    @endif
                </div>
            </header>

            <div class="stack">
                <section id="status-card" class="card status-card" aria-live="polite">
                    <div id="status-symbol" class="status-symbol" aria-hidden="true">
                        <span class="spinner"></span>
                    </div>
                    <h2 id="status-title">Vérification du lien…</h2>
                    <p id="status-message">Connexion sécurisée au cabinet en cours.</p>
                </section>

                <div id="ready" class="stack" hidden>
                    <section class="card">
                        <div class="card-body session-row">
                            <div>
                                <p class="eyebrow">Lien temporaire sécurisé</p>
                                <p class="muted">Il expire automatiquement.</p>
                            </div>
                            <output id="countdown" class="countdown" aria-label="Temps restant">--:--</output>
                        </div>
                    </section>

                    <section class="card">
                        <div class="card-body">
                            <h2 class="section-title">Choisir les documents</h2>
                            <p id="limits" class="muted">PDF, JPEG ou PNG</p>

                            <input
                                id="file-input"
                                class="file-input"
                                type="file"
                                accept="application/pdf,image/jpeg,image/png,.pdf,.jpg,.jpeg,.png"
                                multiple
                            >
                            <button id="choose-files" class="picker" type="button">
                                <span>
                                    <span class="picker-icon" aria-hidden="true">↑</span>
                                    Prendre une photo ou choisir des fichiers
                                </span>
                            </button>

                            <ul id="selected-files" class="file-list" aria-label="Fichiers sélectionnés" hidden></ul>
                            <div id="feedback" class="feedback" role="status" aria-live="polite" hidden></div>
                            <div id="upload-progress" class="progress" aria-label="Envoi en cours" hidden></div>

                            <button id="upload-files" class="button" type="button" disabled>Envoyer les fichiers</button>
                        </div>
                    </section>

                    <section id="received-card" class="card" hidden>
                        <div class="card-body">
                            <div class="received-heading">
                                <h2 class="section-title">Fichiers reçus</h2>
                                <span id="received-count" class="received-count">0</span>
                            </div>
                            <ul id="received-files" class="file-list" aria-label="Fichiers reçus"></ul>
                            <button id="complete-upload" class="button button-success" type="button">Terminer l’envoi</button>
                        </div>
                    </section>
                </div>

                <p class="privacy-note">
                    Aucun dossier médical n’est affiché ici. Les documents restent en attente jusqu’à leur vérification sur l’ordinateur du cabinet.
                </p>
            </div>
        </main>

        <script id="upload-configuration" type="application/json" nonce="{{ $nonce }}">{!! json_encode($configuration, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) !!}</script>
        <script nonce="{{ $nonce }}">
            (() => {
                'use strict';

                function consumeVerifierFragment() {
                    const fragment = window.location.hash;
                    window.history.replaceState(null, '', window.location.pathname + window.location.search);
                    const match = /^#v=([A-Za-z0-9_-]{43})$/.exec(fragment);

                    return match ? match[1] : '';
                }

                let verifier = consumeVerifierFragment();
                let configuration = null;

                try {
                    configuration = JSON.parse(document.getElementById('upload-configuration').textContent);
                } catch (_error) {
                    configuration = null;
                }

                const elements = {
                    statusCard: document.getElementById('status-card'),
                    statusSymbol: document.getElementById('status-symbol'),
                    statusTitle: document.getElementById('status-title'),
                    statusMessage: document.getElementById('status-message'),
                    ready: document.getElementById('ready'),
                    countdown: document.getElementById('countdown'),
                    limits: document.getElementById('limits'),
                    fileInput: document.getElementById('file-input'),
                    chooseFiles: document.getElementById('choose-files'),
                    selectedFiles: document.getElementById('selected-files'),
                    feedback: document.getElementById('feedback'),
                    uploadProgress: document.getElementById('upload-progress'),
                    uploadFiles: document.getElementById('upload-files'),
                    receivedCard: document.getElementById('received-card'),
                    receivedCount: document.getElementById('received-count'),
                    receivedFiles: document.getElementById('received-files'),
                    completeUpload: document.getElementById('complete-upload'),
                };

                const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
                let uploadSession = null;
                let chosenFiles = [];
                let busy = false;
                let deadline = 0;
                let countdownTimer = null;

                function validConfiguration(value) {
                    if (!value || typeof value !== 'object' || !/^[A-Za-z0-9_-]{22}$/.test(value.selector)) {
                        return false;
                    }

                    const base = `/upload/${value.selector}`;

                    return value.endpoints
                        && value.endpoints.authorize === `${base}/authorize`
                        && value.endpoints.files === `${base}/files`
                        && value.endpoints.complete === `${base}/complete`;
                }

                function showStatus(tone, symbol, title, message) {
                    elements.ready.hidden = true;
                    elements.statusCard.hidden = false;
                    elements.statusCard.className = `card status-card status-${tone}`;
                    elements.statusSymbol.replaceChildren(document.createTextNode(symbol));
                    elements.statusTitle.textContent = title;
                    elements.statusMessage.textContent = message;
                }

                function showReady() {
                    elements.statusCard.hidden = true;
                    elements.ready.hidden = false;
                }

                function setFeedback(message, success = false) {
                    elements.feedback.hidden = message === '';
                    elements.feedback.className = success ? 'feedback feedback-success' : 'feedback';
                    elements.feedback.textContent = message;
                }

                function formatBytes(value) {
                    if (!Number.isSafeInteger(value) || value < 1) {
                        return '0 o';
                    }

                    const units = ['o', 'Ko', 'Mo', 'Go'];
                    const unit = Math.min(Math.floor(Math.log(value) / Math.log(1024)), units.length - 1);
                    const amount = value / (1024 ** unit);

                    return `${amount >= 10 || unit === 0 ? amount.toFixed(0) : amount.toFixed(1)} ${units[unit]}`;
                }

                function safeInteger(value, minimum, maximum) {
                    return Number.isSafeInteger(value) && value >= minimum && value <= maximum ? value : null;
                }

                function normalizeSession(value) {
                    if (!value || typeof value !== 'object' || !['pending', 'uploading'].includes(value.status)) {
                        return null;
                    }

                    const maximumFiles = safeInteger(value.maximum_files, 1, 50);
                    const maximumIndividualBytes = safeInteger(value.maximum_individual_bytes, 1, 1073741824);
                    const maximumTotalBytes = safeInteger(value.maximum_total_bytes, 1, 5368709120);
                    const remainingSeconds = safeInteger(value.remaining_seconds, 0, 86400);

                    if (
                        maximumFiles === null
                        || maximumIndividualBytes === null
                        || maximumTotalBytes === null
                        || remainingSeconds === null
                        || maximumIndividualBytes > maximumTotalBytes
                        || !Array.isArray(value.files)
                        || !Array.isArray(value.allowed_mime_types)
                    ) {
                        return null;
                    }

                    const allowedMimeTypes = value.allowed_mime_types.filter((mime) =>
                        ['application/pdf', 'image/jpeg', 'image/png'].includes(mime),
                    );

                    if (allowedMimeTypes.length < 1) {
                        return null;
                    }

                    const files = [];

                    for (const item of value.files) {
                        if (!item || typeof item !== 'object' || typeof item.name !== 'string') {
                            return null;
                        }

                        const size = safeInteger(item.size, 1, maximumTotalBytes);

                        if (size === null) {
                            return null;
                        }

                        files.push({
                            name: item.name.slice(0, 190),
                            size,
                        });
                    }

                    if (files.length > maximumFiles) {
                        return null;
                    }

                    return {
                        status: value.status,
                        maximumFiles,
                        maximumIndividualBytes,
                        maximumTotalBytes,
                        remainingSeconds,
                        allowedMimeTypes,
                        files,
                    };
                }

                function appendFileRow(list, file) {
                    const row = document.createElement('li');
                    row.className = 'file-row';
                    const name = document.createElement('span');
                    name.className = 'file-name';
                    name.textContent = file.name;
                    const size = document.createElement('span');
                    size.className = 'file-size';
                    size.textContent = formatBytes(file.size);
                    row.append(name, size);
                    list.append(row);
                }

                function renderChosenFiles() {
                    elements.selectedFiles.replaceChildren();

                    for (const file of chosenFiles) {
                        appendFileRow(elements.selectedFiles, file);
                    }

                    elements.selectedFiles.hidden = chosenFiles.length === 0;
                }

                function renderReceivedFiles() {
                    elements.receivedFiles.replaceChildren();

                    for (const file of uploadSession.files) {
                        appendFileRow(elements.receivedFiles, file);
                    }

                    elements.receivedCount.textContent = String(uploadSession.files.length);
                    elements.receivedCard.hidden = uploadSession.files.length === 0;
                }

                function refreshButtons() {
                    const expired = deadline > 0 && Date.now() >= deadline;
                    const canInteract = uploadSession !== null && !busy && !expired;
                    const receivedCount = uploadSession === null ? 0 : uploadSession.files.length;
                    const hasAvailableSlot = uploadSession !== null
                        && receivedCount < uploadSession.maximumFiles;
                    elements.chooseFiles.disabled = !canInteract || !hasAvailableSlot;
                    elements.fileInput.disabled = !canInteract || !hasAvailableSlot;
                    elements.uploadFiles.disabled = !canInteract || chosenFiles.length === 0;
                    elements.completeUpload.disabled = !canInteract || receivedCount === 0;
                    elements.uploadProgress.hidden = !busy;
                    elements.uploadFiles.textContent = busy ? 'Envoi en cours…' : 'Envoyer les fichiers';
                }

                function renderSession(session) {
                    uploadSession = session;
                    deadline = Date.now() + session.remainingSeconds * 1000;
                    elements.limits.textContent = `PDF, JPEG ou PNG · ${session.maximumFiles} fichiers maximum · ${formatBytes(session.maximumIndividualBytes)} par fichier`;
                    renderChosenFiles();
                    renderReceivedFiles();
                    showReady();
                    updateCountdown();
                    refreshButtons();

                    if (countdownTimer !== null) {
                        window.clearInterval(countdownTimer);
                    }

                    countdownTimer = window.setInterval(updateCountdown, 1000);
                }

                function expirePage() {
                    verifier = '';
                    uploadSession = null;
                    chosenFiles = [];
                    busy = false;

                    if (countdownTimer !== null) {
                        window.clearInterval(countdownTimer);
                        countdownTimer = null;
                    }

                    showStatus(
                        'warning',
                        '×',
                        'Lien indisponible',
                        'Ce lien temporaire a expiré ou a été fermé. Demandez un nouveau QR code au cabinet.',
                    );
                }

                function updateCountdown() {
                    const seconds = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));

                    if (seconds < 1) {
                        elements.countdown.textContent = '00:00';
                        expirePage();

                        return;
                    }

                    const minutes = Math.floor(seconds / 60);
                    const remainder = seconds % 60;
                    elements.countdown.textContent = `${String(minutes).padStart(2, '0')}:${String(remainder).padStart(2, '0')}`;
                }

                async function parseJson(response) {
                    const contentType = response.headers.get('content-type') || '';

                    if (!contentType.toLowerCase().includes('application/json')) {
                        return null;
                    }

                    try {
                        return await response.json();
                    } catch (_error) {
                        return null;
                    }
                }

                function commonHeaders(json = false) {
                    const headers = {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    };

                    if (json) {
                        headers['Content-Type'] = 'application/json';
                    }

                    return headers;
                }

                function handleUnavailableResponse(response) {
                    if (response.status === 404 || response.status === 410) {
                        expirePage();

                        return true;
                    }

                    if (response.status === 419) {
                        showStatus(
                            'warning',
                            '↻',
                            'Page expirée',
                            'Pour votre sécurité, rescanner le QR code afin de recommencer.',
                        );

                        return true;
                    }

                    if (response.status === 429) {
                        showStatus(
                            'warning',
                            '!',
                            'Trop de tentatives',
                            'Patientez quelques minutes, puis demandez un nouveau QR code si nécessaire.',
                        );

                        return true;
                    }

                    return false;
                }

                async function authorize() {
                    if (!validConfiguration(configuration) || verifier === '') {
                        showStatus(
                            'warning',
                            '×',
                            'QR code incomplet',
                            'Ouvrez cette page en scannant le QR code affiché sur l’ordinateur du cabinet.',
                        );

                        return;
                    }

                    try {
                        const response = await fetch(configuration.endpoints.authorize, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: commonHeaders(true),
                            body: JSON.stringify({ verifier }),
                            cache: 'no-store',
                            redirect: 'error',
                        });

                        if (handleUnavailableResponse(response)) {
                            return;
                        }

                        const payload = await parseJson(response);
                        const session = response.ok ? normalizeSession(payload) : null;

                        if (session === null) {
                            showStatus(
                                'warning',
                                '!',
                                'Connexion impossible',
                                'Le cabinet n’a pas pu vérifier ce lien. Réessayez ou demandez un nouveau QR code.',
                            );

                            return;
                        }

                        renderSession(session);
                    } catch (_error) {
                        showStatus(
                            'warning',
                            '!',
                            'Cabinet inaccessible',
                            'Vérifiez que le téléphone est connecté au bon réseau, puis rescanner le QR code.',
                        );
                    }
                }

                function selectFiles(files) {
                    setFeedback('');
                    chosenFiles = [];
                    renderChosenFiles();
                    refreshButtons();

                    if (uploadSession === null) {
                        return;
                    }

                    const nextFiles = Array.from(files);

                    if (nextFiles.length === 0) {
                        return;
                    }

                    const remainingSlots = uploadSession.maximumFiles - uploadSession.files.length;

                    if (nextFiles.length > remainingSlots) {
                        setFeedback(`Vous pouvez encore envoyer ${remainingSlots} fichier(s).`);
                        elements.fileInput.value = '';

                        return;
                    }

                    const allowedExtensions = /\.(pdf|jpe?g|png)$/i;

                    for (const file of nextFiles) {
                        if (
                            file.size < 1
                            || file.size > uploadSession.maximumIndividualBytes
                            || !allowedExtensions.test(file.name)
                            || (file.type !== '' && !uploadSession.allowedMimeTypes.includes(file.type))
                        ) {
                            setFeedback('Choisissez uniquement des fichiers PDF, JPEG ou PNG respectant la taille indiquée.');
                            elements.fileInput.value = '';

                            return;
                        }
                    }

                    const currentBytes = uploadSession.files.reduce((total, file) => total + file.size, 0);
                    const selectedBytes = nextFiles.reduce((total, file) => total + file.size, 0);

                    if (currentBytes + selectedBytes > uploadSession.maximumTotalBytes) {
                        setFeedback(`La taille totale dépasse la limite de ${formatBytes(uploadSession.maximumTotalBytes)}.`);
                        elements.fileInput.value = '';

                        return;
                    }

                    chosenFiles = nextFiles;
                    renderChosenFiles();
                    refreshButtons();
                }

                async function uploadFiles() {
                    if (uploadSession === null || verifier === '' || chosenFiles.length === 0 || busy) {
                        return;
                    }

                    busy = true;
                    setFeedback('');
                    refreshButtons();

                    const formData = new FormData();
                    formData.append('verifier', verifier);

                    for (const file of chosenFiles) {
                        formData.append('files[]', file, file.name);
                    }

                    try {
                        const response = await fetch(configuration.endpoints.files, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: commonHeaders(false),
                            body: formData,
                            cache: 'no-store',
                            redirect: 'error',
                        });

                        if (handleUnavailableResponse(response)) {
                            return;
                        }

                        const payload = await parseJson(response);
                        const session = response.ok && payload ? normalizeSession(payload.session) : null;

                        if (session === null) {
                            if (response.status === 413) {
                                setFeedback('Les fichiers sont trop volumineux. Réduisez leur taille puis réessayez.');
                            } else if (response.status === 422) {
                                setFeedback('Un ou plusieurs fichiers ne sont pas valides. Vérifiez leur format et leur taille.');
                            } else {
                                setFeedback('L’envoi a échoué. Vérifiez la connexion puis réessayez.');
                            }

                            return;
                        }

                        chosenFiles = [];
                        elements.fileInput.value = '';
                        renderSession(session);
                        setFeedback('Les fichiers ont été reçus et attendent la vérification du cabinet.', true);
                    } catch (_error) {
                        setFeedback('La connexion a été interrompue. Vérifiez le réseau puis réessayez.');
                    } finally {
                        busy = false;
                        refreshButtons();
                    }
                }

                async function completeUpload() {
                    if (uploadSession === null || uploadSession.files.length === 0 || verifier === '' || busy) {
                        return;
                    }

                    busy = true;
                    setFeedback('');
                    refreshButtons();

                    try {
                        const response = await fetch(configuration.endpoints.complete, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: commonHeaders(true),
                            body: JSON.stringify({ verifier }),
                            cache: 'no-store',
                            redirect: 'error',
                        });

                        if (handleUnavailableResponse(response)) {
                            return;
                        }

                        const payload = await parseJson(response);

                        if (!response.ok || !payload || payload.status !== 'completed') {
                            setFeedback('Impossible de terminer l’envoi. Réessayez dans un instant.');

                            return;
                        }

                        verifier = '';
                        uploadSession = null;

                        if (countdownTimer !== null) {
                            window.clearInterval(countdownTimer);
                            countdownTimer = null;
                        }

                        showStatus(
                            'success',
                            '✓',
                            'Envoi terminé',
                            'Les documents ont été reçus. Ils seront vérifiés sur l’ordinateur du cabinet avant d’être ajoutés au dossier. Vous pouvez fermer cette page.',
                        );
                    } catch (_error) {
                        setFeedback('La connexion a été interrompue. Vérifiez le réseau puis réessayez.');
                    } finally {
                        busy = false;
                        refreshButtons();
                    }
                }

                elements.chooseFiles.addEventListener('click', () => elements.fileInput.click());
                elements.fileInput.addEventListener('change', () => selectFiles(elements.fileInput.files));
                elements.uploadFiles.addEventListener('click', uploadFiles);
                elements.completeUpload.addEventListener('click', completeUpload);

                authorize();
            })();
        </script>
    </body>
</html>
