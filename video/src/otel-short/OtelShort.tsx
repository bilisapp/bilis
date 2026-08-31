import { linearTiming, TransitionSeries } from "@remotion/transitions";
import { fade } from "@remotion/transitions/fade";
import React from "react";
import { FormatProvider, PORTRAIT } from "../brand";
import { Cta } from "./scenes/Cta";
import { FourLines } from "./scenes/FourLines";
import { Free } from "./scenes/Free";
import { Hook } from "./scenes/Hook";
import { OneCommand } from "./scenes/OneCommand";

/**
 * The 9:16 cut, for YouTube Shorts.
 *
 * Not the long video cropped. It is a different argument: the long one teaches
 * six steps, this one proves in forty seconds that there are only three worth
 * showing, and sends the viewer to the rest. Everything sits above a 420px
 * bottom reserve, which is where YouTube draws the title and channel row.
 */
const CUT = 8;

export const SHORT_SCENES = [
  { id: "Hook", durationInFrames: 180 },
  { id: "OneCommand", durationInFrames: 210 },
  { id: "FourLines", durationInFrames: 300 },
  { id: "Free", durationInFrames: 240 },
  { id: "Cta", durationInFrames: 180 },
] as const;

export const SHORT_DURATION =
  SHORT_SCENES.reduce((total, scene) => total + scene.durationInFrames, 0) -
  CUT * (SHORT_SCENES.length - 1);

/**
 * Wraps one scene in the portrait format, for registering it on its own.
 *
 * Without this a scene previewed by itself renders at 1080×1920 while every
 * component still reads the landscape scale, which looks like a layout bug and
 * is really a missing provider.
 */
const inPortrait = (Scene: React.FC): React.FC => {
  const Wrapped: React.FC = () => (
    <FormatProvider format={PORTRAIT}>
      <Scene />
    </FormatProvider>
  );

  return Wrapped;
};

export const HookPortrait = inPortrait(Hook);
export const OneCommandPortrait = inPortrait(OneCommand);
export const FourLinesPortrait = inPortrait(FourLines);
export const FreePortrait = inPortrait(Free);
export const CtaPortrait = inPortrait(Cta);

export const OtelShort: React.FC = () => (
  <FormatProvider format={PORTRAIT}>
    <TransitionSeries>
      <TransitionSeries.Sequence durationInFrames={180} name="Hook">
        <Hook />
      </TransitionSeries.Sequence>
      <TransitionSeries.Transition
        presentation={fade()}
        timing={linearTiming({ durationInFrames: CUT })}
      />

      <TransitionSeries.Sequence durationInFrames={210} name="01 One command">
        <OneCommand />
      </TransitionSeries.Sequence>
      <TransitionSeries.Transition
        presentation={fade()}
        timing={linearTiming({ durationInFrames: CUT })}
      />

      <TransitionSeries.Sequence durationInFrames={300} name="02 Four lines">
        <FourLines />
      </TransitionSeries.Sequence>
      <TransitionSeries.Transition
        presentation={fade()}
        timing={linearTiming({ durationInFrames: CUT })}
      />

      <TransitionSeries.Sequence durationInFrames={240} name="03 Free">
        <Free />
      </TransitionSeries.Sequence>
      <TransitionSeries.Transition
        presentation={fade()}
        timing={linearTiming({ durationInFrames: CUT })}
      />

      <TransitionSeries.Sequence durationInFrames={180} name="CTA">
        <Cta />
      </TransitionSeries.Sequence>
    </TransitionSeries>
  </FormatProvider>
);
