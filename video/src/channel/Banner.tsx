import React from "react";
import { AbsoluteFill, Img, staticFile } from "remotion";
import { BilisMark, mark, neutral, sans } from "../brand";

/**
 * The YouTube channel banner.
 *
 * YouTube crops one uploaded image three different ways, so the layout is
 * driven entirely by those crops rather than by the full canvas:
 *
 * - 2560×1440 is what a TV shows — the whole thing.
 * - 2560×423 is what a desktop browser shows — a centre strip.
 * - 1546×423 is what a phone shows, and it is the only region guaranteed to
 *   survive everywhere.
 *
 * So every word and the mark live inside SAFE, and the artwork's job is to be
 * interesting out at the edges where only a TV will ever see it. Anything
 * placed outside SAFE is decoration, never information.
 */
export const BANNER = { width: 2560, height: 1440 } as const;
export const SAFE = { width: 1546, height: 423 } as const;

export const Banner: React.FC<{
  /** Draw the crop boundaries, for checking the layout before uploading. */
  guides?: boolean;
}> = ({ guides = false }) => {
  return (
    <AbsoluteFill style={{ backgroundColor: neutral.background }}>
      {/*
       * Generated artwork, cover-fitted. Slightly desaturated and darkened:
       * the image is close to the palette but the chrome here is achromatic,
       * and the wordmark has to sit on something quiet.
       */}
      <Img
        src={staticFile("banner-art.png")}
        style={{
          width: "100%",
          height: "100%",
          objectFit: "cover",
          filter: "saturate(0.85) brightness(0.92)",
        }}
      />

      {/* Ties the artwork to the exact --background, and darkens the centre. */}
      <AbsoluteFill
        style={{
          background: `radial-gradient(ellipse 46% 70% at 50% 50%, ${neutral.background} 0%, rgba(18,20,25,0.72) 45%, rgba(18,20,25,0) 78%)`,
        }}
      />

      {/* The lockup, centred inside the phone-safe rectangle. */}
      <AbsoluteFill
        style={{
          justifyContent: "center",
          alignItems: "center",
        }}
      >
        <div
          style={{
            width: SAFE.width,
            height: SAFE.height,
            display: "flex",
            flexDirection: "column",
            justifyContent: "center",
            alignItems: "center",
            gap: 34,
          }}
        >
          <div style={{ display: "flex", alignItems: "center", gap: 40 }}>
            <BilisMark width={330} still />
            <span
              style={{
                fontFamily: sans,
                fontSize: 148,
                fontWeight: 600,
                letterSpacing: -6,
                color: mark.cream,
                lineHeight: 1,
              }}
            >
              Bilis
            </span>
          </div>

          {/*
            * Names the standard and the deployment model, and nothing else.
            * Not "for Laravel": Bilis takes OTLP from anything that speaks it,
            * and the channel's Laravel content is a use case, not the scope.
            * Not "logs, traces and metrics" either — metrics are out of scope,
            * and a banner is the last place to imply a capability.
            */}
          <div
            style={{
              fontFamily: sans,
              fontSize: 52,
              fontWeight: 400,
              letterSpacing: -0.8,
              color: neutral.mutedForeground,
            }}
          >
            OpenTelemetry logs and traces, self-hosted
          </div>
        </div>
      </AbsoluteFill>

      {guides ? (
        <AbsoluteFill
          style={{ justifyContent: "center", alignItems: "center" }}
        >
          <div
            style={{
              position: "absolute",
              width: BANNER.width,
              height: SAFE.height,
              border: "3px dashed rgba(69,191,166,0.9)",
            }}
          />
          <div
            style={{
              position: "absolute",
              width: SAFE.width,
              height: SAFE.height,
              border: "3px solid rgba(243,196,64,0.95)",
            }}
          />
        </AbsoluteFill>
      ) : null}
    </AbsoluteFill>
  );
};

export const BannerWithGuides: React.FC = () => <Banner guides />;
