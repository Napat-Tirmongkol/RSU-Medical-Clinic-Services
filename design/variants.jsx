// variants.jsx — Mobile stack + Dark premium variants

function UserHubMobile({ lang = 'en', apptState = 'booked', status = 'active' }) {
  const L = HUB_COPY[lang];
  const [booking, setBooking] = React.useState(false);
  const onBook = () => { setBooking(true); setTimeout(() => setBooking(false), 1800); };
  return (
    <div className="hub hub--mobile">
      <header className="mtop">
        <div className="topbar__brand">
          <IconLogo size={26} accent="var(--accent)"/>
          <div className="topbar__brand-txt"><strong>RSU Medical</strong><small>User Hub</small></div>
        </div>
        <div className="mtop__tools">
          <button className="btn btn--icon" aria-label="Search"><IconSearch size={18}/></button>
          <button className="btn btn--icon" aria-label="Notifications"><IconBell size={18}/><span className="topbar__ping"/></button>
        </div>
      </header>
      <main className="hub__main hub__main--m">
        <div className="hub__greet"><h1>{L.greeting(lang === 'th' ? USER.nameTh.split(' ')[0] : USER.name.split(' ')[0])}</h1><p>Tuesday, 28 April</p></div>
        <div className="bento-m">
          <ProfileCard L={L} lang={lang}/>
          <AppointmentCard L={L} empty={apptState==='empty'} booking={booking} onBook={onBook} onCancel={()=>{}}/>
          <InsuranceCard L={L} status={status}/>
          <QuickActions L={L}/>
          <StatsStrip L={L}/>
          <NotificationsCard L={L}/>
          <CampaignsCard L={L}/>
          <WellnessCard L={L}/>
        </div>
      </main>
      <nav className="mnav" aria-label="Mobile">
        <a className="is-active"><IconUser size={18}/><span>{L.home}</span></a>
        <a><IconCalendar size={18}/><span>{L.appointments}</span></a>
        <a className="mnav__fab" aria-label={L.bookNow}><IconPlus size={22}/></a>
        <a><IconClipboard size={18}/><span>{L.services}</span></a>
        <a><IconSettings size={18}/><span>{L.help}</span></a>
      </nav>
    </div>
  );
}

function UserHubDark({ lang = 'en', apptState = 'booked', status = 'active' }) {
  const L = HUB_COPY[lang];
  const [booking, setBooking] = React.useState(false);
  const onBook = () => { setBooking(true); setTimeout(() => setBooking(false), 1800); };
  return (
    <div className="hub hub--dark">
      <TopBar L={L} lang={lang} onLangToggle={() => {}} />
      <main className="hub__main">
        <div className="hub__greet">
          <h1>{L.greeting(lang === 'th' ? USER.nameTh.split(' ')[0] : USER.name.split(' ')[0])}</h1>
          <p>Tuesday, 28 April · 2 reminders, 1 open campaign</p>
        </div>
        <div className="bento-grid">
          <div className="g-profile"><ProfileCard L={L} lang={lang}/></div>
          <div className="g-appt"><AppointmentCard L={L} empty={apptState==='empty'} booking={booking} onBook={onBook} onCancel={()=>{}}/></div>
          <div className="g-wallet"><InsuranceCard L={L} status={status}/></div>
          <div className="g-qa"><QuickActions L={L}/></div>
          <div className="g-notif"><NotificationsCard L={L}/></div>
          <div className="g-camp"><CampaignsCard L={L}/></div>
          <div className="g-stats"><StatsStrip L={L}/></div>
          <div className="g-well"><WellnessCard L={L}/></div>
        </div>
      </main>
    </div>
  );
}

Object.assign(window, { UserHubMobile, UserHubDark });
