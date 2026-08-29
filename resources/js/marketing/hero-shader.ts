/**
 * Mounts the FoldGradient shader onto the marketing hero.
 *
 * Marketing pages are Blade only, so this is its own tiny Vite entry rather
 * than anything that boots Inertia: the page renders complete without it and
 * the shader is a progressive enhancement layered on top.
 */
import { ShaderMount } from '@paper-design/shaders';

import { fragmentShader } from './fold-gradient-shader';

type Rgba = [number, number, number, number];
type Rgb = [number, number, number];

/** The shader mixes colour in linear space, so undo sRGB's gamma first. */
const toLinear = (channel: number): number => Math.pow(channel, 2.2);

const hexToRgba = (hex: string): Rgba => {
    const [r, g, b] = [1, 3, 5].map((i) =>
        toLinear(parseInt(hex.slice(i, i + 2), 16) / 255),
    );

    return [r, g, b, 1];
};

const hexToRgb = (hex: string): Rgb => hexToRgba(hex).slice(0, 3) as Rgb;

const number = (value: string | undefined, fallback: number): number => {
    const parsed = Number(value);

    return Number.isFinite(parsed) && value !== undefined && value !== ''
        ? parsed
        : fallback;
};

/**
 * A canvas we cannot draw on is worse than no canvas at all: the container
 * keeps its CSS fallback gradient and the entry does nothing.
 */
const supportsWebGl = (): boolean => {
    try {
        return !!document.createElement('canvas').getContext('webgl2');
    } catch {
        return false;
    }
};

/**
 * Read one setting, preferring the light-mode override when the page is on
 * the cream ground: `data-light-colors` wins over `data-colors`, and so on.
 *
 * Light is authored, not derived. The dark palette inverted is a grey mess,
 * so the two grounds carry two sets of stops and the shader is told which.
 */
const setting = (
    element: HTMLElement,
    name: string,
    light: boolean,
): string | undefined => {
    const data = element.dataset;
    const override =
        data[`light${name.charAt(0).toUpperCase()}${name.slice(1)}`];

    return light && override !== undefined ? override : data[name];
};

const palette = (element: HTMLElement, light: boolean): string[] =>
    (setting(element, 'colors', light) ?? '')
        .split(',')
        .map((value) => value.trim())
        .filter((value) => /^#[0-9a-f]{6}$/i.test(value))
        .slice(0, 5);

/** The uniform set for one ground; swapping grounds only swaps these. */
const uniforms = (element: HTMLElement, light: boolean) => {
    const colors = palette(element, light);

    return {
        u_colors: colors.map(hexToRgba),
        u_ncols: colors.length,
        u_back: hexToRgb(setting(element, 'bgColor', light) ?? '#111317'),
        u_shadow: hexToRgb(setting(element, 'shadowColor', light) ?? '#141d29'),
        u_softness: number(setting(element, 'softness', light), 0.9),
        u_saturation: number(setting(element, 'saturation', light), 1.05),
        u_noise: 0,
        u_rotation: number(setting(element, 'rotation', light), -25),
        u_folds: number(setting(element, 'zoom', light), 10),
        u_ribbon: number(setting(element, 'ribbon', light), 0),
        u_ribbonWidth: number(setting(element, 'ribbonWidth', light), 1),
    };
};

/**
 * Mount the shader and keep it on the ground the reader is actually on.
 *
 * Marketing pages follow the operating system only, so the media query is the
 * whole story: one mount, and a uniform swap when the scheme flips.
 */
const bind = (element: HTMLElement): void => {
    const dark = window.matchMedia('(prefers-color-scheme: dark)');

    if (palette(element, !dark.matches).length < 2) {
        return;
    }

    const reduceMotion = window.matchMedia(
        '(prefers-reduced-motion: reduce)',
    ).matches;

    const shader = new ShaderMount(
        element,
        fragmentShader,
        uniforms(element, !dark.matches),
        undefined,
        // Frozen rather than absent when motion is unwelcome: the composition
        // is the point, the drift is the decoration.
        reduceMotion ? 0 : number(element.dataset.speed, 1),
        // A fixed starting frame so a still shader is not a still blank panel.
        reduceMotion ? 9000 : 0,
        1,
        1600 * 900,
    );

    dark.addEventListener('change', (event) => {
        shader.setUniforms(uniforms(element, !event.matches));
    });

    element.dataset.shaderMounted = 'true';
};

const start = (): void => {
    if (!supportsWebGl()) {
        return;
    }

    document
        .querySelectorAll<HTMLElement>('[data-fold-gradient]')
        .forEach(bind);
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
} else {
    start();
}
