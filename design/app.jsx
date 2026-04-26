// app.jsx — assembles everything on a design canvas + tweaks

const TWEAK_DEFAULTS = /*EDITMODE-BEGIN*/{
  "accent": "#2563eb",
  "language": "en",
  "density": "regular",
  "apptState": "booked",
  "insuranceStatus": "active",
  "initialFocus": ""
}/*EDITMODE-END*/;

const ACCENTS = {
  "Medical blue":  { accent: "#2563eb", accent600: "#1d4ed8", a50: "#eff6ff", a100: "#dbeafe", a200: "#bfdbfe" },
  "Wellness teal": { accent: "#0d9488", accent600: "#0f766e", a50: "#f0fdfa", a100: "#ccfbf1", a200: "#99f6e4" },
  "Trust violet":  { accent: "#7c3aed", accent600: "#6d28d9", a50: "#f5f3ff", a100: "#ede9fe", a200: "#ddd6fe" },
  "Coral warm":    { accent: "#ea580c", accent600: "#c2410c", a50: "#fff7ed", a100: "#ffedd5", a200: "#fed7aa" },
};

function applyAccent(rootEl, name) {
  const set = ACCENTS[name] || ACCENTS["Medical blue"];
  rootEl.style.setProperty('--accent', set.accent);
  rootEl.style.setProperty('--accent-600', set.accent600);
  rootEl.style.setProperty('--accent-50', set.a50);
  rootEl.style.setProperty('--accent-100', set.a100);
  rootEl.style.setProperty('--accent-200', set.a200);
}

function App() {
  const [t, setTweak] = useTweaks(TWEAK_DEFAULTS);
  const rootRef = React.useRef(null);

  React.useEffect(() => {
    if (rootRef.current) applyAccent(rootRef.current, t.accent);
  }, [t.accent]);

  const shared = {
    lang: t.language,
    density: t.density,
    apptState: t.apptState,
    status: t.insuranceStatus,
  };

  return (
    <div ref={rootRef} style={{ minHeight: '100vh' }}>
      <DesignCanvas
        title="RSU Medical — User Hub"
        subtitle="Bento grid dashboard · 3 directions"
        initialFocus={t.initialFocus || undefined}>
        <DCSection id="desktop" title="Desktop · Bento grid (spec)">
          <DCArtboard id="desktop-bento" label="Light · Bento grid" width={1400} height={1120}>
            <UserHubDesktop {...shared}/>
          </DCArtboard>
          <DCArtboard id="desktop-bento-empty" label="Empty state · no appointment" width={1400} height={1120}>
            <UserHubDesktop {...shared} apptState="empty"/>
          </DCArtboard>
        </DCSection>

        <DCSection id="mobile" title="Mobile · Single-column stack">
          <DCArtboard id="mobile-stack" label="Mobile · iOS size" width={390} height={1200}>
            <UserHubMobile {...shared}/>
          </DCArtboard>
          <DCArtboard id="mobile-empty" label="Mobile · empty appt" width={390} height={1200}>
            <UserHubMobile {...shared} apptState="empty"/>
          </DCArtboard>
        </DCSection>

        <DCSection id="dark" title="Dark premium variant">
          <DCArtboard id="dark-bento" label="Dark · Bento grid" width={1400} height={1120}>
            <UserHubDark {...shared}/>
          </DCArtboard>
        </DCSection>
      </DesignCanvas>

      <TweaksPanel>
        <TweakSection label="Brand" />
        <TweakSelect  label="Accent"   value={t.accent} options={Object.keys(ACCENTS)}
                      onChange={(v) => setTweak('accent', v)} />
        <TweakRadio   label="Language" value={t.language} options={['en','th']}
                      onChange={(v) => setTweak('language', v)} />

        <TweakSection label="Layout" />
        <TweakRadio   label="Density"  value={t.density} options={['compact','regular','comfy']}
                      onChange={(v) => setTweak('density', v)} />

        <TweakSection label="Card states" />
        <TweakRadio   label="Appointment" value={t.apptState} options={['booked','empty']}
                      onChange={(v) => setTweak('apptState', v)} />
        <TweakRadio   label="Insurance"   value={t.insuranceStatus} options={['active','expired']}
                      onChange={(v) => setTweak('insuranceStatus', v)} />
      </TweaksPanel>
    </div>
  );
}

ReactDOM.createRoot(document.getElementById('root')).render(<App/>);
