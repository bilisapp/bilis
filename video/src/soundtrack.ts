/**
 * Which track each composition plays.
 *
 * Set an entry to `null` and that composition renders silent. Set it to an
 * object and the file must exist at `public/<src>` — Remotion resolves
 * `staticFile()` at render time and will fail loudly if it does not, which is
 * the behaviour you want: a video that was supposed to have music and silently
 * does not is worse than a render that stops.
 *
 * Tracks come from the YouTube Audio Library (studio.youtube.com → Music).
 * Two things to carry across when you add one:
 *
 * - **Attribution.** Filter to "Attribution not required" if you can. If you
 *   use a track that requires it, paste the exact credit line the library
 *   gives you into `attribution` here *and* into the video description. The
 *   library's licence is only honoured if the credit ships with the video.
 * - **`startAtSeconds`.** Most library tracks spend their first bars arriving.
 *   A Short cannot afford that, so start where the track already has energy —
 *   usually somewhere between 8 and 30 seconds in.
 */
export type Soundtrack = {
  /** Path inside `public/`. */
  src: string;
  /** Seconds to skip at the head of the track. */
  startAtSeconds: number;
  /** 0–1. */
  volume: number;
  /** Exact credit line, or null when the track needs none. */
  attribution: string | null;
  /** Override the default fades. A punchy cut wants the music to land at once. */
  fadeInFrames?: number;
  fadeOutFrames?: number;
};

/**
 * "Eviction" — Silent Partner, YouTube Audio Library. 2:41.
 *
 * Measured rather than guessed: the track has no quiet intro. It opens
 * mid-groove around −4 dB of its peak, steps up at 0:13 and sits at full
 * energy from 0:16 to the end. So there is no "wait for it to start" problem,
 * only a choice between the lighter opening bars and the full arrangement.
 */
const EVICTION = "music/eviction-silent-partner.mp3";

export const SOUNDTRACK: Record<string, Soundtrack | null> = {
  /**
   * 79s explainer. People are reading code, and music that competes with
   * reading is worse than silence — so this is low enough to be felt rather
   * than listened to. Starts at 0 so the track's own step-up at 0:13 lands
   * under the move from the title into the first real step.
   */
  OtelSetup: {
    src: EVICTION,
    startAtSeconds: 0,
    /*
     * Measured, not chosen by ear: this lands the render near −19 LUFS.
     * YouTube normalises to about −14 and only ever turns audio *down*, so a
     * mix at −22 stays at −22 and viewers assume the video has no sound.
     */
    volume: 0.34,
    attribution: null,
  },

  /**
   * 36s calm Short. Same trick, louder: the lighter opening bars carry the
   * hook, and the arrangement fills out as the steps begin.
   */
  OtelShort: {
    src: EVICTION,
    startAtSeconds: 0,
    volume: 0.5,
    attribution: null,
  },

  /**
   * 27s high-energy Short. Starts at 0:16, past the build, so the first frame
   * is already at full tilt — and lands in two frames rather than fading, because
   * a hard cut into a fading-in track is the one place the pacing sags.
   */
  OtelShortPunchy: {
    src: EVICTION,
    startAtSeconds: 16,
    /* ≈ −13 LUFS, just under YouTube's −14 target, so it is not attenuated. */
    volume: 0.65,
    attribution: null,
    fadeInFrames: 2,
    fadeOutFrames: 26,
  },
};
