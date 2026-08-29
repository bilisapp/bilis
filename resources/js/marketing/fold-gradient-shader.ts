// FoldGradient — a custom Paper shader.
// Domain-warped fbm light-sheets, lit and graded, in a single pass so it
// mounts directly on @paper-design/shaders' ShaderMount.
// Paper's runtime provides u_time, u_resolution, u_pixelRatio automatically.

export const fragmentShader = /* glsl */ `#version 300 es
precision mediump float;   // match Paper's vertex shader precision (u_resolution link)

uniform float u_time;
uniform vec2  u_resolution;

// custom uniforms (driven by DialKit → React props)
uniform vec4  u_colors[5];
uniform float u_ncols;
uniform vec3  u_back;       // colour of the black gaps
uniform vec3  u_shadow;     // tint that bleeds into the shadowed edges
uniform float u_softness;
uniform float u_saturation;
uniform float u_noise;      // dither amount (0 = smooth)
uniform float u_rotation;   // degrees
uniform float u_folds;      // "zoom" — warp scale
uniform float u_ribbon;     // 0 = off (original look); >0 = discrete strip cuts
uniform float u_ribbonWidth; // strip width multiplier (1 = default)

out vec4 fragColor;

// ── noise ────────────────────────────────────────────────────────────────
mat2 rot(float a){ float c=cos(a), s=sin(a); return mat2(c,-s,s,c); }

// sin-free 2D->1D hash (Dave Hoskins) — faster & precision-stable vs sin(dot())
float hash12(vec2 p){
  vec3 p3=fract(vec3(p.xyx)*0.1031);
  p3+=dot(p3, p3.yzx+33.33);
  return fract((p3.x+p3.y)*p3.z);
}
float vnoise(vec2 p){
  vec2 ip=floor(p), u=fract(p); u=u*u*(3.0-2.0*u);
  float r=mix(mix(hash12(ip),          hash12(ip+vec2(1,0)), u.x),
              mix(hash12(ip+vec2(0,1)), hash12(ip+vec2(1,1)), u.x), u.y);
  return r*r;                                   // squared → deeper valleys (keeps the look)
}
const mat2 m2=mat2(0.8,-0.6,0.6,0.8);           // inter-octave rotation
float fbm(vec2 p){
  float f=0.0;
  f+=0.5000*vnoise(p); p=m2*p*2.02;
  f+=0.2500*vnoise(p); p=m2*p*2.03;
  f+=0.1250*vnoise(p);
  return f/0.875;
}
// classic 3-level domain warp: fbm(p + 1.76*fbm(p + 4*fbm(p)))
// 5 fbm evals = 15 vnoise = the dominant cost of the shader. The exact
// offsets/constants define the sheet shapes — do not touch.
float pat(vec2 p, out float hue){
  vec2 q=vec2(fbm(p), fbm(p+vec2(5.2,1.3)));
  vec2 r=vec2(fbm(p+4.0*q+vec2(1.7,9.2)), fbm(p+4.0*q+vec2(8.3,2.8)));
  hue=clamp(r.x*0.95+0.03, 0.0, 1.0);           // colour from bounded r (no drift)
  r+=u_time*0.045;                              // time animates sheet motion only
  return fbm(p+1.76*r);
}

// ── colour ───────────────────────────────────────────────────────────────
vec3 palette(float x){
  x=clamp(x,0.0,1.0)*(u_ncols-1.0);
  int i=int(floor(x)); float f=fract(x);
  return mix(u_colors[i].rgb, u_colors[min(i+1,int(u_ncols)-1)].rgb, smoothstep(0.0,1.0,f));
}
// ordered 4x4 Bayer threshold in [0,1) — anti-banding dither
float bayer4(vec2 p){
  int m[16]=int[16](0,8,2,10, 12,4,14,6, 3,11,1,9, 15,7,13,5);
  return float(m[int(mod(p.y,4.0))*4 + int(mod(p.x,4.0))])/16.0;
}

void main(){
  vec2 uv=gl_FragCoord.xy/u_resolution.xy;
  vec2 dir=normalize(vec2(0.66,0.75));          // smear direction (along the sheets)
  vec2 perp=vec2(-dir.y,dir.x);
  float sm=0.045+(2.0-u_softness)*0.075;         // softness → smear length (inverted for dial feel)
  float jit=hash12(floor(gl_FragCoord.xy*0.5))-0.5;   // per-2x2-quad → clean derivatives

  // The uv→pattern warp (aspect → rotate → zoom) is affine, so hoist it out
  // of the tap loop: warp the base point and the smear axes once, instead of
  // 13 rot()/scale evaluations per pixel. Numerically identical result.
  float asp=u_resolution.x/u_resolution.y;
  mat2  R=rot(radians(u_rotation));
  float zsc=29.16/u_folds;                      // higher zoom → bigger sheets
  vec2  pBase=vec2((uv.x-0.5)*asp, uv.y-0.5)*R*zsc;
  vec2  wDir =vec2(dir.x*asp,  dir.y )*R*zsc;   // smear direction, in warp space
  vec2  wPerp=vec2(perp.x*asp, perp.y)*R*zsc;

  // ── ribbon cuts (u_ribbon > 0) ─────────────────────────────────────────
  // Quantize the across-sheet coordinate into bands; each band shears the
  // field by a per-band amount along the sheet direction. Constant within a
  // band → interior stays buttery; discontinuous at the boundary → a razor-
  // straight cut, like stacked glass strips. u_ribbon=0 is byte-identical
  // to the original design.
  float bandGain=1.0;
  if(u_ribbon>0.001){
    // warp-anchored strip coordinate — strips scale with zoom, like the
    // sheets themselves. 3.24 = zsc at zoom 9, so zoom 9 is the reference.
    float d=dot(pBase, normalize(vec2(wPerp)))/3.24;
    float t=d/(0.16*max(u_ribbonWidth,0.05));          // ~8 strips at width 1
    t+=0.35*sin(t*1.7+2.1);                            // irregular rhythm
    float band=floor(t), fb=fract(t);
    // feather: blend per-strip values with the previous strip over a small
    // edge zone instead of hard-switching — crisp cut, but not a 0px razor
    float k=smoothstep(0.0,0.16,fb);
    float bA=band-1.0, bB=band;
    // along-strip coordinate — BEFORE the shear, so the length mask stays
    // anchored to the screen rather than travelling with the flow
    float s=dot(pBase, normalize(vec2(wDir)))/3.24;
    // shear the strip's content along the sheet direction — the field
    // "jumps" at each cut, like offset glass strips over the flow
    float shear=mix(hash12(vec2(bA,7.7)), hash12(vec2(bB,7.7)), k);
    pBase+=normalize(wDir)*(shear-0.5)*4.5*u_ribbon;
    // finite, irregularly-lengthed strips with soft rounded ends: each strip
    // is a capsule along the diagonal, fading to black past its extent
    float c =mix(hash12(vec2(bA,9.1)), hash12(vec2(bB,9.1)), k)-0.5;   // centre
    float hl=0.42+0.40*mix(hash12(vec2(bA,11.3)), hash12(vec2(bB,11.3)), k); // half-length
    float cap=1.0-smoothstep(hl-0.24, hl+0.14, abs(s-c*0.8));
    // per-strip exposure: some strips glow, some fall to near-black — this
    // creates the confident dark gaps between bright ribbons
    float e=mix(hash12(vec2(bA,3.3)), hash12(vec2(bB,3.3)), k);
    // normalized ≈1.0 mean so the slider redistributes light (bright strips
    // brighten past 1, dark strips darken) instead of dimming everything
    bandGain=mix(1.0, (0.62+1.25*e*e)*cap*1.3, u_ribbon);
    // per-strip focus: some strips crisp, some dreamy (depth-of-field feel)
    float fo=mix(hash12(vec2(bA,5.5)), hash12(vec2(bB,5.5)), k);
    sm*=mix(1.0, 0.70+1.25*fo, u_ribbon);
  }

  vec3 L=normalize(vec3(0.55,0.35,0.55));
  vec3 HL=normalize(L+vec3(0.0,0.0,1.0));

  // directional smear of the LIT field → soft raked streaks.
  // 1 field eval per tap; normals from screen-space derivatives (near-free).
  float lum=0.0, hue=0.0, wsum=0.0, bloom=0.0;
  const int K=6;
  // ribbon mode: bigger, softer forms (lower field frequency)
  float fscale=mix(1.0, 0.52, clamp(u_ribbon,0.0,1.0));
  for(int i=-K;i<=K;i++){
    float fi=(float(i)+jit)/float(K);
    float w=exp(-fi*fi*2.5);
    vec2 off=wDir*(fi*sm)+wPerp*(fi*sm*0.11);
    float hh; float h=pat((pBase+off)*fscale, hh);
    vec2 g=vec2(dFdx(h),dFdy(h))*u_resolution.xy*0.0022;
    vec3 N=normalize(vec3(-g, 0.5));
    float diff=clamp(dot(N,L),0.0,1.0);
    float crest=pow(clamp(dot(N,HL),0.0,1.0),16.0);
    float ribbon=smoothstep(0.14,0.92,h);
    // ribbon mode: kill the derivative lighting (it's where the grain and
    // pixel-level jaggies live) and shade from the smooth field value alone
    float baseW =mix(0.34,0.72,u_ribbon);
    float diffW =mix(0.90,0.08,u_ribbon);
    float crestW=mix(0.60,0.0 ,u_ribbon);
    float sheen =pow(h,5.0)*0.45*u_ribbon;            // soft h-based hot spots
    float lv=(ribbon*(baseW+diff*diffW)+crest*crestW+sheen)*smoothstep(0.02,0.45,h);
    lum+=lv*w; hue+=hh*w; wsum+=w;
    bloom+=smoothstep(0.55,1.0,lv)*w;
  }
  lum/=wsum; hue/=wsum; bloom/=wsum;
  lum*=bandGain;                                 // per-strip exposure (ribbon mode)

  // corner falloff (subtle vignette keeps the edges from washing out)
  vec2 qc=(uv-0.5); qc.x*=asp; lum*=1.0-dot(qc,qc)*0.45;

  // tri-tone: black gaps → shadow tint → palette as sheets light up.
  // lum-weighted so the bright sheet edges reach the hot end of the palette.
  vec3 grad=palette(hue*0.62 + lum*0.42);
  vec3 col=mix(u_back, u_shadow, smoothstep(0.015,0.30,lum));
  col=mix(col, grad, smoothstep(0.22,0.72,lum));
  col+=grad*bloom*0.55;

  // finishing: ACES tonemap → gamma → saturation
  col=clamp((col*(2.51*col+0.03))/(col*(2.43*col+0.59)+0.14),0.0,1.0);
  col=pow(col, vec3(1.0/2.2));
  float luma=dot(col, vec3(0.2126,0.7152,0.0722));
  col=clamp(mix(vec3(luma), col, u_saturation), 0.0, 1.0);

  // ordered dither (0 = smooth) + always-on tiny dither to kill 8-bit banding
  float lvl=mix(255.0, 14.0, clamp(u_noise,0.0,1.0));
  col+=(bayer4(gl_FragCoord.xy)-0.5)/lvl;
  col=floor(col*lvl+0.5)/lvl;

  fragColor=vec4(col,1.0);
}
`;
