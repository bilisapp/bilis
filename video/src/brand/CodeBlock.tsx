import React from "react";
import { Easing, Interactive, interpolate, useCurrentFrame } from "remotion";
import { mono, sans } from "./fonts";
import { useFormat } from "./format";
import { EASE, mark, neutral, severity } from "./theme";

export type Lang = "bash" | "env" | "php" | "text";

/**
 * A deliberately thin highlighter.
 *
 * Full syntax colouring would put five hues on screen and spend the palette on
 * punctuation. The interface reserves colour for data, so here only three
 * things get any: comments recede to muted, string and value literals take the
 * debug teal, and keywords and flags take the mark's gold. Everything else is
 * plain foreground, which is most of it.
 */
const tokenize = (line: string, lang: Lang): React.ReactNode => {
  if (lang === "text") {
    return line;
  }

  const commentAt = lang === "php" ? line.indexOf("//") : line.indexOf("#");

  if (commentAt === 0 || (commentAt > 0 && lang !== "php")) {
    return (
      <>
        <span>{line.slice(0, commentAt)}</span>
        <span style={{ color: neutral.mutedForeground }}>
          {line.slice(commentAt)}
        </span>
      </>
    );
  }

  if (lang === "env") {
    /*
     * A continuation line — an indented value wrapped onto its own line so a
     * long endpoint stays readable on a portrait frame. It is still a value,
     * so it is still coloured as one; without this the same URL is teal on a
     * wide frame and grey on a narrow one.
     */
    if (/^\s/.test(line) && line.trim() !== "") {
      return <span style={{ color: severity.debug }}>{line}</span>;
    }

    const eq = line.indexOf("=");

    if (eq === -1) {
      return <span style={{ color: neutral.mutedForeground }}>{line}</span>;
    }

    return (
      <>
        <span style={{ color: mark.gold }}>{line.slice(0, eq)}</span>
        <span style={{ color: neutral.mutedForeground }}>=</span>
        <span style={{ color: severity.debug }}>{line.slice(eq + 1)}</span>
      </>
    );
  }

  const keywords =
    lang === "php"
      ? /\b(return|function|use|env|config|true|false|null|filter_var|public|private|class)\b/g
      : /(^|\s)(composer|php|artisan|npx|curl|require|vendor:publish|test|--[a-z-]+)/g;

  const parts: React.ReactNode[] = [];
  let last = 0;

  // Strings first: a keyword inside a quoted value is not a keyword.
  const strings = [...line.matchAll(/(['"])(?:(?!\1).)*\1/g)];

  const emitPlain = (text: string, key: string) => {
    let cursor = 0;
    const out: React.ReactNode[] = [];

    for (const m of text.matchAll(keywords)) {
      const at = m.index ?? 0;
      const matched = m[0];
      out.push(text.slice(cursor, at));
      out.push(
        <span key={`${key}-${at}`} style={{ color: mark.gold }}>
          {matched}
        </span>,
      );
      cursor = at + matched.length;
    }

    out.push(text.slice(cursor));

    return out;
  };

  for (const m of strings) {
    const at = m.index ?? 0;
    parts.push(...emitPlain(line.slice(last, at), `p${at}`));
    parts.push(
      <span key={`s${at}`} style={{ color: severity.debug }}>
        {m[0]}
      </span>,
    );
    last = at + m[0].length;
  }

  parts.push(...emitPlain(line.slice(last), "tail"));

  return <>{parts}</>;
};

/**
 * A code panel that reveals line by line.
 *
 * The reveal is the point: on a tutorial slide the viewer reads at the speed
 * lines appear, so the pace of the file is the pace of the explanation. Lines
 * named in `highlight` stay full contrast while the rest dim, which is how a
 * later scene points at one line of a file already on screen.
 */
export const CodeBlock: React.FC<{
  code: string;
  lang?: Lang;
  /** Filename or command context, shown in the panel's header. */
  title?: string;
  delay?: number;
  /** Frames between one line appearing and the next. 0 reveals the block whole. */
  stagger?: number;
  /** 1-based line numbers to keep lit once `highlightAt` passes. */
  highlight?: number[];
  highlightAt?: number;
  /** Defaults to the active format's code size. */
  fontSize?: number;
  showLineNumbers?: boolean;
}> = ({
  code,
  lang = "bash",
  title,
  delay = 0,
  stagger = 3,
  highlight,
  highlightAt,
  fontSize,
  showLineNumbers = false,
}) => {
  const frame = useCurrentFrame();
  const format = useFormat();
  const size = fontSize ?? format.type.code;
  const lines = code.replace(/\n+$/, "").split("\n");

  return (
    <Interactive.Div
      name={title ? `Code · ${title}` : "Code"}
      style={{
        backgroundColor: neutral.code,
        border: `1px solid ${neutral.border}`,
        borderRadius: 18,
        overflow: "hidden",
        flexShrink: 0,
        opacity: interpolate(frame, [delay, delay + 14], [0, 1], {
          extrapolateLeft: "clamp",
          extrapolateRight: "clamp",
          easing: Easing.bezier(...EASE),
        }),
        translate: interpolate(
          frame,
          [delay, delay + 20],
          ["0px 22px", "0px 0px"],
          {
            extrapolateLeft: "clamp",
            extrapolateRight: "clamp",
            easing: Easing.bezier(...EASE),
          },
        ),
      }}
    >
      {title ? (
        <div
          style={{
            display: "flex",
            alignItems: "center",
            gap: 14,
            padding: "18px 30px",
            borderBottom: `1px solid ${neutral.border}`,
            backgroundColor: neutral.card,
            fontFamily: sans,
            fontSize: format.type.label,
            color: neutral.mutedForeground,
            letterSpacing: 0.4,
          }}
        >
          <span
            style={{
              width: 10,
              height: 10,
              borderRadius: 999,
              backgroundColor: neutral.input,
            }}
          />
          {title}
        </div>
      ) : null}

      <div
        style={{
          padding: "30px 34px",
          fontFamily: mono,
          fontSize: size,
          lineHeight: 1.62,
          color: neutral.codeForeground,
          whiteSpace: "pre",
        }}
      >
        {lines.map((line, index) => {
          const lineDelay = delay + 8 + index * stagger;
          const isLit =
            !highlight ||
            highlightAt === undefined ||
            frame < highlightAt ||
            highlight.includes(index + 1);

          return (
            <div
              key={index}
              style={{
                display: "flex",
                gap: 24,
                opacity:
                  interpolate(frame, [lineDelay, lineDelay + 10], [0, 1], {
                    extrapolateLeft: "clamp",
                    extrapolateRight: "clamp",
                    easing: Easing.bezier(...EASE),
                  }) * (isLit ? 1 : 0.28),
                minHeight: size * 1.62,
              }}
            >
              {showLineNumbers ? (
                <span
                  style={{
                    color: neutral.input,
                    width: 44,
                    textAlign: "right",
                    userSelect: "none",
                  }}
                >
                  {index + 1}
                </span>
              ) : null}
              <span>{tokenize(line, lang)}</span>
            </div>
          );
        })}
      </div>
    </Interactive.Div>
  );
};
