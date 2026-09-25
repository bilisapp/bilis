import React from "react";
import { Composition, Folder, Still } from "remotion";
import "./index.css";
import { video } from "./brand";
import { Banner, BannerWithGuides } from "./channel/Banner";
import { OtelSetup, OTEL_DURATION } from "./otel/OtelSetup";
import { Correlate } from "./otel/scenes/Correlate";
import { Endpoint } from "./otel/scenes/Endpoint";
import { Install } from "./otel/scenes/Install";
import { Instrumented } from "./otel/scenes/Instrumented";
import { Outcome } from "./otel/scenes/Outcome";
import { Outro } from "./otel/scenes/Outro";
import { Production } from "./otel/scenes/Production";
import { Signals } from "./otel/scenes/Signals";
import { Title } from "./otel/scenes/Title";
import { MetricsLaunch, METRICS_LAUNCH_DURATION } from "./metrics-launch/MetricsLaunch";
import { Ask, Cause, Cta, Early, Fleet, Hook, Meet, Open, Pager, Why } from "./metrics-launch/scenes";
import { OtelPunchy, PUNCHY_DURATION } from "./otel-punchy/OtelPunchy";
import {
  CtaPortrait,
  FourLinesPortrait,
  FreePortrait,
  HookPortrait,
  OneCommandPortrait,
  OtelShort,
  SHORT_DURATION,
} from "./otel-short/OtelShort";

/**
 * Each scene is registered on its own as well as inside the video, so
 * double-clicking a sequence in the timeline jumps straight to it and a scene
 * can be re-timed without scrubbing the whole thing.
 */
export const RemotionRoot: React.FC = () => {
  return (
    <>
      <Composition
        id="OtelSetup"
        component={OtelSetup}
        durationInFrames={OTEL_DURATION}
        fps={video.fps}
        width={video.width}
        height={video.height}
      />

      <Composition
        id="MetricsLaunch"
        component={MetricsLaunch}
        durationInFrames={METRICS_LAUNCH_DURATION}
        fps={30}
        width={1920}
        height={1080}
      />

      <Folder name="MetricsLaunch-Scenes">
        <Composition id="Launch-Hook" component={Hook} durationInFrames={165} fps={30} width={1920} height={1080} />
        <Composition id="Launch-Pager" component={Pager} durationInFrames={135} fps={30} width={1920} height={1080} />
        <Composition id="Launch-Meet" component={Meet} durationInFrames={195} fps={30} width={1920} height={1080} />
        <Composition id="Launch-Why" component={Why} durationInFrames={120} fps={30} width={1920} height={1080} />
        <Composition id="Launch-Early" component={Early} durationInFrames={180} fps={30} width={1920} height={1080} />
        <Composition id="Launch-Cause" component={Cause} durationInFrames={195} fps={30} width={1920} height={1080} />
        <Composition id="Launch-Fleet" component={Fleet} durationInFrames={180} fps={30} width={1920} height={1080} />
        <Composition id="Launch-Ask" component={Ask} durationInFrames={180} fps={30} width={1920} height={1080} />
        <Composition id="Launch-Open" component={Open} durationInFrames={120} fps={30} width={1920} height={1080} />
        <Composition id="Launch-Cta" component={Cta} durationInFrames={165} fps={30} width={1920} height={1080} />
      </Folder>

      <Folder name="Channel">
        <Still id="YouTubeBanner" component={Banner} width={2560} height={1440} />
        <Still
          id="YouTubeBanner-Guides"
          component={BannerWithGuides}
          width={2560}
          height={1440}
        />
      </Folder>

      <Composition
        id="OtelShort"
        component={OtelShort}
        durationInFrames={SHORT_DURATION}
        fps={30}
        width={1080}
        height={1920}
      />

      <Composition
        id="OtelShortPunchy"
        component={OtelPunchy}
        durationInFrames={PUNCHY_DURATION}
        fps={30}
        width={1080}
        height={1920}
      />

      <Folder name="OtelShort-Scenes">
        <Composition
          id="Short-Hook"
          component={HookPortrait}
          durationInFrames={180}
          fps={30}
          width={1080}
          height={1920}
        />
        <Composition
          id="Short-OneCommand"
          component={OneCommandPortrait}
          durationInFrames={210}
          fps={30}
          width={1080}
          height={1920}
        />
        <Composition
          id="Short-FourLines"
          component={FourLinesPortrait}
          durationInFrames={300}
          fps={30}
          width={1080}
          height={1920}
        />
        <Composition
          id="Short-Free"
          component={FreePortrait}
          durationInFrames={240}
          fps={30}
          width={1080}
          height={1920}
        />
        <Composition
          id="Short-Cta"
          component={CtaPortrait}
          durationInFrames={180}
          fps={30}
          width={1080}
          height={1920}
        />
      </Folder>

      <Folder name="OtelSetup-Scenes">
        <Composition
          id="Scene-Title"
          component={Title}
          durationInFrames={150}
          fps={30}
          width={1920}
          height={1080}
        />
        <Composition
          id="Scene-Outcome"
          component={Outcome}
          durationInFrames={270}
          fps={30}
          width={1920}
          height={1080}
        />
        <Composition
          id="Scene-Install"
          component={Install}
          durationInFrames={270}
          fps={30}
          width={1920}
          height={1080}
        />
        <Composition
          id="Scene-Endpoint"
          component={Endpoint}
          durationInFrames={300}
          fps={30}
          width={1920}
          height={1080}
        />
        <Composition
          id="Scene-Instrumented"
          component={Instrumented}
          durationInFrames={300}
          fps={30}
          width={1920}
          height={1080}
        />
        <Composition
          id="Scene-Signals"
          component={Signals}
          durationInFrames={300}
          fps={30}
          width={1920}
          height={1080}
        />
        <Composition
          id="Scene-Production"
          component={Production}
          durationInFrames={300}
          fps={30}
          width={1920}
          height={1080}
        />
        <Composition
          id="Scene-Correlate"
          component={Correlate}
          durationInFrames={300}
          fps={30}
          width={1920}
          height={1080}
        />
        <Composition
          id="Scene-Outro"
          component={Outro}
          durationInFrames={270}
          fps={30}
          width={1920}
          height={1080}
        />
      </Folder>
    </>
  );
};
