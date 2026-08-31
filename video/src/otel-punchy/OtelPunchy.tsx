import { TransitionSeries } from "@remotion/transitions";
import React from "react";
import { AbsoluteFill } from "remotion";
import { FormatProvider, Music, PORTRAIT, Ticker } from "../brand";
import { SOUNDTRACK } from "../soundtrack";
import {
  BlackBox,
  EveryQuery,
  Loop,
  NotAnymore,
  StepOne,
  StepThree,
  StepTwo,
  ThreeSteps,
} from "./scenes/Beats";

/**
 * The high-energy cut of the Short.
 *
 * Same product, opposite pacing. Every rule the rest of this template follows
 * about restraint is off here on purpose, and it is quarantined to this one
 * composition so it never leaks into the app's own surfaces.
 *
 * Three things do the work:
 *
 * - **Hard cuts, no crossfades.** A dissolve is a rest, and the whole point is
 *   that there is nowhere to rest. Each scene's own `PunchIn` supplies the
 *   energy the transition would have.
 * - **Nothing sits longer than three seconds.** The longest beat is the .env,
 *   because it is the only one anybody has to actually read.
 * - **It loops.** The last beat and the first are both a dark frame with type
 *   centred, so a replay does not announce itself — and a Short that loops
 *   twice counts as two views.
 */
const BEATS = [
  { component: BlackBox, durationInFrames: 66 },
  { component: NotAnymore, durationInFrames: 78 },
  { component: EveryQuery, durationInFrames: 96 },
  { component: ThreeSteps, durationInFrames: 54 },
  { component: StepOne, durationInFrames: 108 },
  { component: StepTwo, durationInFrames: 168 },
  { component: StepThree, durationInFrames: 132 },
  { component: Loop, durationInFrames: 96 },
] as const;

export const PUNCHY_DURATION = BEATS.reduce(
  (total, beat) => total + beat.durationInFrames,
  0,
);

export const OtelPunchy: React.FC = () => {
  const music = SOUNDTRACK.OtelShortPunchy;

  return (
  <FormatProvider format={PORTRAIT}>
    <AbsoluteFill>
      <TransitionSeries>
        <TransitionSeries.Sequence durationInFrames={66} name="Black box">
          <BlackBox />
        </TransitionSeries.Sequence>
        <TransitionSeries.Sequence durationInFrames={78} name="Not anymore">
          <NotAnymore />
        </TransitionSeries.Sequence>
        <TransitionSeries.Sequence durationInFrames={96} name="Every query">
          <EveryQuery />
        </TransitionSeries.Sequence>
        <TransitionSeries.Sequence durationInFrames={54} name="3 steps">
          <ThreeSteps />
        </TransitionSeries.Sequence>
        <TransitionSeries.Sequence durationInFrames={108} name="Step 1">
          <StepOne />
        </TransitionSeries.Sequence>
        <TransitionSeries.Sequence durationInFrames={168} name="Step 2">
          <StepTwo />
        </TransitionSeries.Sequence>
        <TransitionSeries.Sequence durationInFrames={132} name="Step 3">
          <StepThree />
        </TransitionSeries.Sequence>
        <TransitionSeries.Sequence durationInFrames={96} name="Loop">
          <Loop />
        </TransitionSeries.Sequence>
      </TransitionSeries>

      <Ticker total={PUNCHY_DURATION} />

      {music ? (
        <Music
          src={music.src}
          startAtSeconds={music.startAtSeconds}
          volume={music.volume}
          fadeInFrames={music.fadeInFrames}
          fadeOutFrames={music.fadeOutFrames}
        />
      ) : null}
    </AbsoluteFill>
  </FormatProvider>
  );
};
