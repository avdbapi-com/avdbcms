// Pointer effects for the rich tier with a hovering fine pointer (pointerFx). app.ts loads this chunk at the
// first idle moment of a public page, never on the Upload18 embed shell; admin pages load it for the cursor
// halo alone (initPointerFx({haloOnly: true})), which the layout's former inline follower drew there too.
// One controller replaces the engines that all ran on the same card at once: the layout's two inline tilt
// engines, its spotlight and cursor-halo engines, and the motion chunk's anime.js card tilt, magnet and click
// burst.
// - Delegated listeners only (pointerover, pointermove, pointerout and click on the document).
// - An element's box is read when the pointer enters it, and again after a scroll that moves it (the page's,
//   or a rail's around it), a resize, or once it is BOX_TTL_MS old: a layout shift (a late block above it, a
//   panel growing under the pointer) moves it without a scroll or a resize. So at most about five reads per
//   second per hovered element, and never more than one per frame.
// - At most one write per element per animation frame, all after the reads.
// - The spotlight, the tilt glare and the hover hotspot are the radial gradients of card-sized host elements
//   (inset 0, the card's radius), centred by two custom properties written on the host itself: only that leaf
//   restyles, and it repaints inside the card's own layer. No extra compositor layer and no rounded clip
//   mask, which cost a software compositor (no GPU acceleration: a blocklisted GPU, a VM, a remote desktop)
//   more per frame than the repaint does.
// - Leaving an element clears its inline transform, so its CSS transition takes it back and no 3D layer
//   stays behind.
// The admin switches decide what exists, as before: data-avd-tilt-3d (3D tilt and glare),
// data-avd-card-spotlight, data-avd-cursor-trail, and AVD_SETTINGS.animeEffects for the effects that came
// from the motion chunk (subtle tilt and hotspot, magnet, click burst). The hyper_dynamic motion engine keeps
// its !important hover transform, which wins over the inline tilt.
import {allows, onTierChange} from './tier';

type Box = {left: number; top: number; width: number; height: number; at: number};
type Hosts = {spot?: HTMLElement; glare?: HTMLElement; hotspot?: HTMLElement};
type Burst = {node: HTMLElement; rays: HTMLElement[]; animations: Animation[]};

export type PointerFxOptions = {
    // The cursor halo only (admin pages: none of the card, magnet or burst effects ever ran there).
    haloOnly?: boolean;
};

// Framework-managed media subtrees (player, preroll ad, upload, embed shell): no transform, write or burst.
const HANDS_OFF = 'video-player, video-upload, .avd-player-stage, .avd-preroll-ad, .avd-embed-shell';
// The 3D tilt and its glare (the layout's former inline engines).
const TILT_CARDS = '.avd-video-card, .avd-short-card';
// The subtle tilt and the hover hotspot (the motion chunk's former anime.js card effect). Cards only: the panels that
// hold controls (the search panel's Filters, the channel panel's Subscribe, the comments' sort buttons) no longer tilt
// and lift under the pointer, so those buttons never move (member design 4.2; a hover sweep measured 2 frames each).
// The watch page's related list (.suggested_video.avd-related-card) is a list of rows, each with its Watch Later and
// Share buttons on the picture: its rows stay still too (11-13 buttons used to move on every watch page).
const SUBTLE_CARDS = [
    '.avd-video-card',
    '.video-card-modern',
    '.avd-channel-card',
].join(',');
// A card that tilts holds still while the pointer is on one of its controls (its quick actions, its menu, the channel
// link): the button stays where the pointer found it, and nothing under it repaints (member design 4.2: the hit box
// never moves; 0 frames 150 ms after the pointer rests on a control). Off it, the card follows the pointer again.
const CARD_CONTROLS = 'button, .btn, [role="button"], input, select, textarea, label, .dropdown-menu, a.avd-channel-name';
const SPOT_CARDS = '.avd-video-card, .avd-bento-card, .studio-preview-card, .studio-card-preview';
// No .btn (member design 4.2, perf-1, watch-4): the magnet pulled every button toward the pointer by up to 4px and
// scaled it 1.025, so a button at the edge of a scroll box overflowed it, toggled its scrollbar and slid out from under
// the pointer and back (the owner's "+ Create Video" flash). The small round controls below keep a 2px pull.
const MAGNET_TARGETS = '.avd-icon-button, .avd-sidebar-toggle, .avd-tag, .avd-social-btn, .avd-player-control, .avd-action-pill';
// Where no pointer effect runs (4.1a, 4.2): member markup, forms and the back office. Scroll containers are found per
// element (insideScroller below).
const CALM_ZONES = '.avd-mk, form, [data-avd-back-office]';
// The magnet's pull, at most this many pixels on each axis, toward the pointer.
const MAGNET_PX = 2;

// Half the .avd-cursor-halo box (layout.blade.php).
const HALO_HALF = 200;
// The halo's catch-up: 40% of the distance left after each 60 Hz frame (0.4^9: under 0.2px of a 760px move in 150 ms).
const HALO_LEFT_PER_FRAME = 0.4;
const HALO_FRAME_MS = 1000 / 60;
// A cached box older than this is read again on the next pointer frame.
const BOX_TTL_MS = 200;
const EASE_OUT = 'cubic-bezier(.33,1,.68,1)'; // anime 'out(3)'

const clamp = (value: number, min: number, max: number): number => Math.max(min, Math.min(max, value));

// Loading placeholders (feed skeleton cards) never got the tilt or the spotlight.
const isSkeleton = (card: Element): boolean => card.firstElementChild?.classList.contains('avd-placeholder-thumb') === true;

// Same budget as the motion chunk's anime.js pointer effects: pages with many cards kept them off.
const pageMotionLoad = (): number => document.querySelectorAll(`${SUBTLE_CARDS}, .comment, .list-group-item, .dropdown-item`).length
    - document.querySelectorAll('.avd-video-card > .avd-placeholder-thumb').length;

let started = false;

// A scroll container between the element and the page (overflow auto, scroll or overlay on either axis, scrollable or
// not): a transform there can overflow it and toggle its scrollbar (perf-1). The document's own scroller does not count.
const insideScroller = (element: Element): boolean => {
    for (let node = element.parentElement; node && node !== document.body && node !== document.documentElement; node = node.parentElement) {
        const style = window.getComputedStyle(node);

        if (/(auto|scroll|overlay)/.test(style.overflowX + ' ' + style.overflowY)) {
            return true;
        }
    }

    return false;
};

export const initPointerFx = (options: PointerFxOptions = {}): void => {
    if (started || !allows('pointerFx') || typeof requestAnimationFrame !== 'function') {
        return;
    }

    const root = document.documentElement;
    const cards = options.haloOnly !== true;

    // Member pages never run a pointer effect (4.1a: nothing on them follows the pointer); the admin keeps its halo.
    if (cards && (root.hasAttribute('data-avd-member') || root.hasAttribute('data-avd-back-office'))) {
        return;
    }
    const animeEffects = cards && window.AVD_SETTINGS?.animeEffects !== false && (root.dataset.avdMotion ?? 'full') === 'full';
    const load = animeEffects ? pageMotionLoad() : Infinity;
    const fx = {
        // getAttribute: dataset cannot name it (no camel case before the digit).
        tilt: cards && root.getAttribute('data-avd-tilt-3d') === 'on' && allows('tilt'),
        spot: cards && root.dataset.avdCardSpotlight !== 'off' && allows('spotlight'),
        subtle: animeEffects && load <= 96 && allows('tilt'),
        magnet: animeEffects && load <= 128,
        burst: animeEffects && load <= 128 && allows('clickBurst') && typeof Element.prototype.animate === 'function',
        halo: root.dataset.avdCursorTrail === 'on' && allows('cursorHalo'),
    };

    // The layout's click-ripple engine gives rich mouse presses the old motion chunk's ripple only under the same
    // page budget as the magnet and the burst (see richMouseRipple() there).
    if (cards) {
        root.setAttribute('data-avd-pointer-budget', animeEffects && load <= 128 ? 'ok' : 'over');
    }

    if (!fx.tilt && !fx.spot && !fx.subtle && !fx.magnet && !fx.burst && !fx.halo) {
        return;
    }

    started = true;

    // Its !important hover transform wins over any inline tilt, so a tilt write there would be wasted.
    const hyper = root.dataset.avdMotionEngine === 'hyper_dynamic';
    const boxes = new Map<HTMLElement, Box>();
    const hosts = new WeakMap<HTMLElement, Hosts>();
    const placed = new WeakMap<HTMLElement, string>();
    const bursts = new WeakMap<HTMLElement, Burst>();
    // Calm targets (a calm zone or a scroll container around them), decided once per element on its first hover.
    const calm = new WeakMap<Element, boolean>();
    const isCalm = (element: Element): boolean => {
        let known = calm.get(element);

        if (known === undefined) {
            known = element.closest(CALM_ZONES) !== null || insideScroller(element);
            calm.set(element, known);
        }

        return known;
    };
    // The magnet's last pull per element: a box read while the pull is applied is taken back to the element's place.
    const pulled = new WeakMap<HTMLElement, {x: number; y: number}>();
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let tiltCard: HTMLElement | null = null;
    let subtleCard: HTMLElement | null = null;
    let spotCard: HTMLElement | null = null;
    let magnetTarget: HTMLElement | null = null;
    let pointerX = 0;
    let pointerY = 0;
    let moved = false;
    let frame = 0;
    let halo: HTMLElement | null = null;
    let haloShown = false;
    let haloX = -500;
    let haloY = -500;
    // The time of the halo's last step (0: at rest), for the frame-rate independent easing below.
    let haloAt = 0;

    if (fx.halo && document.body) {
        halo = document.querySelector<HTMLElement>('.avd-cursor-halo');

        if (!halo) {
            halo = document.createElement('div');
            halo.className = 'avd-cursor-halo';
            halo.setAttribute('aria-hidden', 'true');
            document.body.appendChild(halo);
        }
    }

    // Read once per element entered, then again after a scroll that moves it or a resize (both forget it,
    // below), or when the cached box is older than BOX_TTL_MS. `now` is the frame's timestamp.
    const boxOf = (element: HTMLElement, now: number): Box => {
        let box = boxes.get(element);

        if (!box || now - box.at > BOX_TTL_MS) {
            const rect = element.getBoundingClientRect();
            box = {
                left: rect.left,
                top: rect.top,
                width: rect.width || 1,
                height: rect.height || 1,
                at: now,
            };
            boxes.set(element, box);
        }

        return box;
    };

    // The card's host for one effect: its direct child with that class. The server-rendered card template
    // already has the spotlight and glare hosts; the others are added on the first hover and kept.
    const hostIn = (card: HTMLElement, kind: keyof Hosts, hostClass: string): HTMLElement => {
        const known = hosts.get(card) ?? {};
        let host = known[kind];

        if (host && host.parentElement === card) {
            return host;
        }

        host = undefined;

        for (let i = 0; i < card.children.length; i += 1) {
            if (card.children[i].classList.contains(hostClass)) {
                host = card.children[i] as HTMLElement;
                break;
            }
        }

        if (!host) {
            host = document.createElement('span');
            host.className = hostClass;
            host.setAttribute('aria-hidden', 'true');
            card.appendChild(host);
        }

        known[kind] = host;
        hosts.set(card, known);

        return host;
    };

    // Centres a host's gradient (motion.scss: `circle at var(--avd-fx-x) var(--avd-fx-y)`). Written on the host,
    // a leaf, so nothing else restyles; skipped when the position did not change.
    const place = (host: HTMLElement, x: string, y: string): void => {
        const at = `${x} ${y}`;

        if (placed.get(host) === at) {
            return;
        }

        placed.set(host, at);
        host.style.setProperty('--avd-fx-x', x);
        host.style.setProperty('--avd-fx-y', y);
    };

    // Elements carrying an inline transform written here (cards and magnet targets).
    const written = new Set<HTMLElement>();

    const setTransform = (element: HTMLElement, value: string): void => {
        element.style.transform = value;
        written.add(element);
    };

    const holdsTransform = (element: HTMLElement): boolean => element === tiltCard || element === subtleCard || element === magnetTarget;

    // Cards held still (CARD_CONTROLS) at the transform they had when the pointer reached the control: its running
    // transition is stopped there (the CSS transition of the card would otherwise carry it on for a quarter second).
    const held = new Set<HTMLElement>();
    let holding = false;

    const hold = (card: HTMLElement | null): void => {
        if (!card || held.has(card)) {
            return;
        }

        const current = window.getComputedStyle(card).transform;
        card.style.transition = 'none';
        card.style.transform = current && current !== 'none' ? current : '';
        // release() clears it when the pointer leaves the card (the card's own :hover state may be what was frozen).
        written.add(card);
        held.add(card);
    };

    const unhold = (card: HTMLElement): void => {
        if (held.delete(card)) {
            card.style.transition = '';
        }
    };

    // An element that no effect follows any more gets the inline transform written here cleared (the CSS
    // transition takes it back to its resting or :hover state) and its box forgotten.
    const release = (element: HTMLElement | null): void => {
        if (!element || holdsTransform(element)) {
            return;
        }

        unhold(element);

        if (written.delete(element)) {
            element.style.transform = '';
            pulled.delete(element);
        }

        if (element !== spotCard) {
            boxes.delete(element);
        }
    };

    // On a control inside the followed card: hold the card (and its glare, hotspot and spotlight) still; off it, let go.
    const syncHold = (inside: Element | null): void => {
        const control = inside ? inside.closest(CARD_CONTROLS) : null;
        const cards = [tiltCard, subtleCard].filter((card): card is HTMLElement => card !== null && control !== null && card.contains(control));

        holding = cards.length > 0;
        // Let go of a card the pointer left for another card's control or for the card's own surface.
        Array.from(held).forEach((card) => {
            if (!cards.includes(card)) {
                unhold(card);
            }
        });
        cards.forEach(hold);
    };

    const retarget = (target: Element | null): void => {
        const inside = target && !target.closest(HANDS_OFF) ? target : null;
        let nextTilt = fx.tilt && inside ? inside.closest<HTMLElement>(TILT_CARDS) : null;
        let nextSpot = fx.spot && inside ? inside.closest<HTMLElement>(SPOT_CARDS) : null;
        let nextSubtle = fx.subtle && inside ? inside.closest<HTMLElement>(SUBTLE_CARDS) : null;
        let nextMagnet = fx.magnet && inside ? inside.closest<HTMLElement>(MAGNET_TARGETS) : null;

        if (nextTilt && (isSkeleton(nextTilt) || isCalm(nextTilt))) nextTilt = null;
        if (nextSpot && (isSkeleton(nextSpot) || isCalm(nextSpot))) nextSpot = null;
        if (nextSubtle && isCalm(nextSubtle)) nextSubtle = null;
        if (nextMagnet && isCalm(nextMagnet)) nextMagnet = null;

        if (nextTilt === tiltCard && nextSpot === spotCard && nextSubtle === subtleCard && nextMagnet === magnetTarget) {
            syncHold(inside);
            return;
        }

        const previous = [tiltCard, subtleCard, spotCard, magnetTarget];

        if (subtleCard && subtleCard !== nextSubtle) {
            subtleCard.classList.remove('avd-spotlight-active');
        }

        tiltCard = nextTilt;
        spotCard = nextSpot;
        subtleCard = nextSubtle;
        magnetTarget = nextMagnet;
        previous.forEach(release);
        syncHold(inside);

        if (tiltCard && tiltCard.matches('.avd-video-card')) hostIn(tiltCard, 'glare', 'avd-card-glare');
        if (spotCard) hostIn(spotCard, 'spot', 'avd-card-spotlight');

        if (subtleCard) {
            hostIn(subtleCard, 'hotspot', 'avd-card-hotspot');
            subtleCard.classList.add('avd-spotlight-active');
        }
    };

    const flush = (now: number): void => {
        frame = 0;
        const x = pointerX;
        const y = pointerY;

        if (moved && !holding) {
            moved = false;
            // Reads first (cached boxes, see boxOf), then writes only.
            const tiltBox = tiltCard ? boxOf(tiltCard, now) : null;
            const subtleBox = subtleCard ? boxOf(subtleCard, now) : null;
            const spotBox = spotCard ? boxOf(spotCard, now) : null;
            const magnetBox = magnetTarget ? boxOf(magnetTarget, now) : null;

            if (tiltCard && tiltBox) {
                // The former inline engine B: 8deg at the edges, scale 1.02, perspective 1000px, and its glare
                // at the same percentages of the card.
                const percentX = clamp((x - tiltBox.left - tiltBox.width / 2) / (tiltBox.width / 2), -1, 1);
                const percentY = clamp((y - tiltBox.top - tiltBox.height / 2) / (tiltBox.height / 2), -1, 1);

                if (!(hyper && tiltCard.matches('.avd-video-card'))) {
                    setTransform(tiltCard, `perspective(1000px) rotateX(${(-percentY * 8).toFixed(2)}deg) rotateY(${(percentX * 8).toFixed(2)}deg) scale3d(1.02, 1.02, 1.02)`);
                }

                const glare = hosts.get(tiltCard)?.glare;

                if (glare) {
                    place(glare, `${((percentX + 1) * 50).toFixed(1)}%`, `${((percentY + 1) * 50).toFixed(1)}%`);
                }
            }

            if (subtleCard && subtleBox) {
                // The former anime.js tilt: rotateX 3.8deg / rotateY 4.8deg at the edges, lifted 4px. Where the 3D
                // tilt runs on the same card, it wins (as it did over the anime.js tween). Clamped: a box read
                // just before a layout shift must not tilt the card past its edges.
                const progressX = clamp((x - subtleBox.left) / subtleBox.width, 0, 1);
                const progressY = clamp((y - subtleBox.top) / subtleBox.height, 0, 1);

                if (subtleCard !== tiltCard && !(hyper && subtleCard.matches('.avd-video-card'))) {
                    setTransform(subtleCard, `rotateX(${((0.5 - progressY) * 3.8).toFixed(4)}deg) rotateY(${((progressX - 0.5) * 4.8).toFixed(4)}deg) translateY(-4px)`);
                }

                const hotspot = hosts.get(subtleCard)?.hotspot;

                if (hotspot) {
                    // The old hotspot sat at whole percentages of the card.
                    place(hotspot, `${Math.round(progressX * 100)}%`, `${Math.round(progressY * 100)}%`);
                }
            }

            if (spotCard && spotBox) {
                const spot = hosts.get(spotCard)?.spot;

                if (spot) {
                    // The pointer, relative to the card (the old --mouse-x/--mouse-y).
                    place(spot, `${(x - spotBox.left).toFixed(1)}px`, `${(y - spotBox.top).toFixed(1)}px`);
                }
            }

            if (magnetTarget && magnetBox) {
                // At most MAGNET_PX toward the pointer, translate only (no scale), measured from the element's own place:
                // the cached box may hold the last pull, which is taken back first, so the pull never feeds on itself.
                const last = pulled.get(magnetTarget) ?? {x: 0, y: 0};
                const left = magnetBox.left - last.x;
                const top = magnetBox.top - last.y;
                const pullX = (clamp((x - left) / magnetBox.width, 0, 1) - 0.5) * 2 * MAGNET_PX;
                const pullY = (clamp((y - top) / magnetBox.height, 0, 1) - 0.5) * 2 * MAGNET_PX;
                pulled.set(magnetTarget, {x: pullX, y: pullY});
                setTransform(magnetTarget, `translate(${pullX.toFixed(2)}px, ${pullY.toFixed(2)}px)`);
            }
        }

        if (halo && haloShown) {
            // A compositor transform (no left/top layout) that settles within 150 ms at any frame rate (member design
            // 4.2: nothing repaints 150 ms after the pointer rests on a control). The old 0.14-per-frame easing kept the
            // halo moving under a just-hovered button for about a second (a hover sweep measured 2 distinct frames).
            const now = performance.now();
            const dt = haloAt ? Math.min(now - haloAt, 100) : HALO_FRAME_MS;
            haloAt = now;
            const k = 1 - Math.pow(HALO_LEFT_PER_FRAME, dt / HALO_FRAME_MS);
            haloX += (x - haloX) * k;
            haloY += (y - haloY) * k;
            halo.style.transform = `translate3d(${(haloX - HALO_HALF).toFixed(1)}px, ${(haloY - HALO_HALF).toFixed(1)}px, 0)`;

            if (Math.abs(x - haloX) > 0.2 || Math.abs(y - haloY) > 0.2) {
                frame = window.requestAnimationFrame(flush);
            } else {
                haloAt = 0;
            }
        }
    };

    const schedule = (): void => {
        if (!frame) {
            frame = window.requestAnimationFrame(flush);
        }
    };

    const onPointerOver = (event: PointerEvent): void => {
        if (event.pointerType === 'touch') {
            return;
        }

        pointerX = event.clientX;
        pointerY = event.clientY;
        retarget(event.target instanceof Element ? event.target : null);
        moved = true;
        schedule();
    };

    const onPointerMove = (event: PointerEvent): void => {
        if (event.pointerType === 'touch') {
            return;
        }

        pointerX = event.clientX;
        pointerY = event.clientY;
        moved = true;

        if (halo && !haloShown) {
            haloShown = true;
            halo.style.opacity = '1';
        }

        if (tiltCard || subtleCard || spotCard || magnetTarget || haloShown) {
            schedule();
        }
    };

    // Leaving the window.
    const onPointerOut = (event: PointerEvent): void => {
        if (!event.relatedTarget && event.pointerType !== 'touch') {
            retarget(null);
            onMouseLeave();
        }
    };

    const onMouseLeave = (): void => {
        if (halo && haloShown) {
            haloShown = false;
            halo.style.opacity = '0';
        }
    };

    const forgetBoxes = (): void => boxes.clear();

    // A scroll moves the boxes inside its scroller only. The page's scroll forgets them all; a rail's (the home
    // marquees scroll theirs every frame) only the ones of elements inside that rail, so a hovered feed card
    // keeps its box while the rails above it run.
    const onScroll = (event: Event): void => {
        if (!boxes.size) {
            return;
        }

        const scroller = event.target;

        if (!(scroller instanceof Element) || scroller === document.documentElement || scroller === document.body) {
            boxes.clear();
            return;
        }

        boxes.forEach((_box, element) => {
            if (scroller.contains(element)) {
                boxes.delete(element);
            }
        });
    };

    // The motion chunk's former anime.js burst: six rays from the click point, rich tier only. One burst node
    // per target, reused, and taken out again once its rays are done.
    const onClick = (event: MouseEvent): void => {
        if (!(event.target instanceof Element)) {
            return;
        }

        const target = event.target.closest<HTMLElement>(MAGNET_TARGETS);

        if (!target || target.dataset.avdNoBurst === 'true' || target.closest(HANDS_OFF)) {
            return;
        }

        const rect = target.getBoundingClientRect();
        let burst = bursts.get(target);

        if (!burst) {
            const node = document.createElement('span');
            node.className = 'avd-click-burst';
            node.setAttribute('aria-hidden', 'true');
            const rays = Array.from({length: 6}, (_, index) => {
                const ray = document.createElement('span');
                ray.style.setProperty('--avd-burst-angle', `${index * 60}deg`);
                node.appendChild(ray);

                return ray;
            });
            burst = {node, rays, animations: []};
            bursts.set(target, burst);
        }

        const current = burst;
        current.animations.forEach((animation) => animation.cancel());
        current.node.style.left = `${event.clientX - rect.left}px`;
        current.node.style.top = `${event.clientY - rect.top}px`;

        if (current.node.parentNode !== target) {
            target.appendChild(current.node);
        }

        current.animations = current.rays.map((ray, index) => ray.animate([
            {opacity: 0.78, transform: 'translateX(0rem) scaleX(.35)'},
            {opacity: 0, transform: 'translateX(1.05rem) scaleX(1)'},
        ], {duration: 520, delay: index * 18, easing: EASE_OUT, fill: 'both'}));

        const last = current.animations[current.animations.length - 1];
        last.onfinish = () => {
            if (current.animations[current.animations.length - 1] !== last) return;
            current.animations.forEach((animation) => animation.cancel());
            current.animations = [];
            current.node.remove();
        };
    };

    const dispose = (): void => {
        document.removeEventListener('pointerover', onPointerOver);
        document.removeEventListener('pointermove', onPointerMove);
        document.removeEventListener('pointerout', onPointerOut);
        document.removeEventListener('mouseleave', onMouseLeave);
        document.removeEventListener('scroll', onScroll, true);
        document.removeEventListener('click', onClick);
        window.removeEventListener('resize', forgetBoxes);
        if (reducedMotion.removeEventListener) reducedMotion.removeEventListener('change', onPreferenceChange);
        else reducedMotion.removeListener(onPreferenceChange);
        stopTier();

        if (frame) window.cancelAnimationFrame(frame);
        frame = 0;
        retarget(null);
        boxes.clear();
        onMouseLeave();
        root.removeAttribute('data-avd-pointer-budget');
    };

    // A tier downgrade or a reduced-motion switch ends every pointer effect for the rest of the visit.
    const onPreferenceChange = (): void => {
        if (!allows('pointerFx')) dispose();
    };
    const stopTier = onTierChange(onPreferenceChange);

    if (reducedMotion.addEventListener) reducedMotion.addEventListener('change', onPreferenceChange);
    else reducedMotion.addListener(onPreferenceChange);

    document.addEventListener('pointerover', onPointerOver, {passive: true});
    document.addEventListener('pointermove', onPointerMove, {passive: true});
    document.addEventListener('pointerout', onPointerOut, {passive: true});
    document.addEventListener('mouseleave', onMouseLeave, {passive: true});
    // Scrolls (captured: the page and every scroller) and resizes move the boxes; see onScroll.
    document.addEventListener('scroll', onScroll, {capture: true, passive: true});
    window.addEventListener('resize', forgetBoxes, {passive: true});

    if (fx.burst) {
        document.addEventListener('click', onClick);
    }
};
