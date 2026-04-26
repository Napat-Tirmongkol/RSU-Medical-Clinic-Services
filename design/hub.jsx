// hub.jsx — RSU Medical User Hub (bento grid, desktop-first)

const HUB_COPY = {
  en: {
    hello: 'Welcome back,',
    memberSince: 'Member since 2023',
    verified: 'Identity Verified',
    upcoming: 'Upcoming appointment',
    upcomingEmpty: 'No upcoming appointments',
    upcomingEmptyHint: 'Book a clinic visit or E-Vax slot in seconds.',
    bookNow: 'Book now',
    cancel: 'Cancel appointment',
    addToCal: 'Add to calendar',
    booking: 'Booking…',
    insurance: 'Accident insurance',
    active: 'Active',
    expired: 'Expired',
    remaining: 'Remaining balance',
    coverage: 'Coverage until 31 Aug 2026',
    quickActions: 'Quick actions',
    bookNew: 'Book campaign',
    bookNewSub: 'Queue & clinics',
    history: 'Visit history',
    historySub: '12 past visits',
    records: 'Medical records',
    recordsSub: 'Labs & notes',
    settings: 'Settings',
    settingsSub: 'Privacy & alerts',
    notifications: 'Notifications',
    viewAll: 'View all',
    wellness: 'Wellness tip',
    wellnessBody: 'Annual physical due in August. Book early to get morning slots.',
    campaigns: 'Campaigns open',
    hpv: 'HPV vaccination — free for students',
    hpvDate: 'Until 30 Jun',
    flu: 'Flu shot — staff & dependents',
    fluDate: 'Until 15 Jul',
    eye: 'Eye screening — free add-on',
    eyeDate: 'Until 5 Aug',
    greeting: (n) => `Good morning, ${n}`,
    search: 'Search services, records, campaigns…',
    home: 'Home',
    services: 'Services',
    appointments: 'Appointments',
    help: 'Help',
    notifItems: [
      { t: 'Appointment reminder', b: 'Dr. Siriporn — tomorrow at 10:30', time: '2h ago', kind: 'info' },
      { t: 'Cancellation email sent', b: 'RE: flu-shot slot on 24 Apr',     time: 'Yesterday', kind: 'ok' },
      { t: 'Lab results ready',      b: 'CBC & lipid panel from 18 Apr',    time: '2 days ago', kind: 'warn' },
    ],
  },
  th: {
    hello: 'ยินดีต้อนรับ,',
    memberSince: 'สมาชิกตั้งแต่ปี 2023',
    verified: 'ยืนยันตัวตนแล้ว',
    upcoming: 'นัดหมายถัดไป',
    upcomingEmpty: 'ยังไม่มีนัดหมาย',
    upcomingEmptyHint: 'จองคิวคลินิกหรือฉีดวัคซีนได้ในไม่กี่คลิก',
    bookNow: 'จองเลย',
    cancel: 'ยกเลิกนัดหมาย',
    addToCal: 'เพิ่มในปฏิทิน',
    booking: 'กำลังจอง…',
    insurance: 'ประกันอุบัติเหตุ',
    active: 'ใช้งานได้',
    expired: 'หมดอายุ',
    remaining: 'ยอดคงเหลือ',
    coverage: 'คุ้มครองถึง 31 ส.ค. 2569',
    quickActions: 'ทางลัด',
    bookNew: 'จองแคมเปญ',
    bookNewSub: 'คิวและคลินิก',
    history: 'ประวัติการเข้าพบ',
    historySub: '12 ครั้งที่ผ่านมา',
    records: 'เวชระเบียน',
    recordsSub: 'ผลแล็บและบันทึก',
    settings: 'ตั้งค่า',
    settingsSub: 'ความเป็นส่วนตัว',
    notifications: 'การแจ้งเตือน',
    viewAll: 'ดูทั้งหมด',
    wellness: 'คำแนะนำสุขภาพ',
    wellnessBody: 'ตรวจสุขภาพประจำปี ครบกำหนดเดือน ส.ค. จองเช้าได้คิวไว.',
    campaigns: 'แคมเปญที่เปิดรับ',
    hpv: 'วัคซีน HPV — ฟรีสำหรับนักศึกษา',
    hpvDate: 'ถึง 30 มิ.ย.',
    flu: 'วัคซีนไข้หวัดใหญ่ — บุคลากรและครอบครัว',
    fluDate: 'ถึง 15 ก.ค.',
    eye: 'ตรวจสายตา — เพิ่มเติมฟรี',
    eyeDate: 'ถึง 5 ส.ค.',
    greeting: (n) => `สวัสดีตอนเช้า ${n}`,
    search: 'ค้นหาบริการ ประวัติ แคมเปญ…',
    home: 'หน้าหลัก',
    services: 'บริการ',
    appointments: 'นัดหมาย',
    help: 'ช่วยเหลือ',
    notifItems: [
      { t: 'เตือนนัดหมาย',        b: 'พญ.ศิริพร — พรุ่งนี้ 10:30 น.',      time: '2 ชม.ที่แล้ว', kind: 'info' },
      { t: 'ส่งอีเมลยกเลิกแล้ว',  b: 'คิววัคซีนไข้หวัดใหญ่ 24 เม.ย.',    time: 'เมื่อวาน',   kind: 'ok' },
      { t: 'ผลแล็บพร้อมแล้ว',     b: 'CBC และไขมัน ผลตรวจ 18 เม.ย.',      time: '2 วันที่แล้ว', kind: 'warn' },
    ],
  },
};

const USER = { name: 'Thanakorn P.', id: '6504123 · Student', nameTh: 'ธนากร พ.', avatar: null };

// ───────── primitives ─────────
function Bento({ children, className = '', style, onClick, hoverable = false, ...p }) {
  return (
    <div
      onClick={onClick}
      className={`bento ${hoverable ? 'bento--hover' : ''} ${className}`}
      style={style}
      {...p}>
      {children}
    </div>
  );
}
function Badge({ children, tone = 'ok', icon }) {
  return (
    <span className={`badge badge--${tone}`}>
      {icon}{children}
    </span>
  );
}
function KBD({ children }) { return <kbd className="kbd">{children}</kbd>; }

// ───────── cards ─────────
function ProfileCard({ L, lang, compact }) {
  return (
    <Bento className="profile">
      <div className="profile__bg" aria-hidden="true">
        <svg viewBox="0 0 400 220" preserveAspectRatio="xMidYMid slice">
          <defs>
            <radialGradient id="pb1" cx="15%" cy="10%" r="60%">
              <stop offset="0" stopColor="var(--accent)" stopOpacity=".28"/>
              <stop offset="1" stopColor="var(--accent)" stopOpacity="0"/>
            </radialGradient>
            <radialGradient id="pb2" cx="100%" cy="100%" r="70%">
              <stop offset="0" stopColor="var(--accent)" stopOpacity=".18"/>
              <stop offset="1" stopColor="var(--accent)" stopOpacity="0"/>
            </radialGradient>
          </defs>
          <rect width="400" height="220" fill="url(#pb1)"/>
          <rect width="400" height="220" fill="url(#pb2)"/>
          <g stroke="var(--accent)" strokeOpacity=".08" fill="none">
            <circle cx="360" cy="40" r="80"/>
            <circle cx="360" cy="40" r="120"/>
            <circle cx="360" cy="40" r="160"/>
          </g>
        </svg>
      </div>
      <div className="profile__row">
        <div className="avatar">
          <svg width="56" height="56" viewBox="0 0 56 56"><rect width="56" height="56" rx="16" fill="var(--accent-100)"/><text x="28" y="36" textAnchor="middle" fontFamily="Prompt,Inter" fontWeight="600" fontSize="22" fill="var(--accent-600)">TP</text></svg>
          <span className="avatar__tick" aria-hidden="true"><IconCheck size={10} stroke={3}/></span>
        </div>
        <div className="profile__meta">
          <div className="profile__hello">{L.hello}</div>
          <div className="profile__name">{lang === 'th' ? USER.nameTh : USER.name}</div>
          <div className="profile__id">{USER.id} · {L.memberSince}</div>
        </div>
        <button className="btn btn--ghost profile__qr" aria-label="Show QR"><IconQr size={18}/></button>
      </div>
      <div className="profile__verified">
        <span className="profile__shield"><IconShield size={14} stroke={2.2}/></span>
        <span>{L.verified}</span>
        <span className="profile__dot"/>
        <span className="profile__micro">Thai National ID · Cleared 22 Feb</span>
      </div>
    </Bento>
  );
}

function AppointmentCard({ L, empty, booking, onBook, onCancel }) {
  if (empty) {
    return (
      <Bento className="appt appt--empty">
        <div className="card-head">
          <h3>{L.upcoming}</h3>
        </div>
        <div className="appt__empty">
          <div className="appt__empty-art" aria-hidden="true">
            <svg viewBox="0 0 120 90" width="120" height="90">
              <rect x="10" y="14" width="100" height="70" rx="10" fill="var(--accent-50)" stroke="var(--accent-200)"/>
              <path d="M10 34h100" stroke="var(--accent-200)"/>
              <circle cx="30" cy="24" r="2.5" fill="var(--accent)"/>
              <circle cx="90" cy="24" r="2.5" fill="var(--accent)"/>
              <rect x="22" y="44" width="18" height="14" rx="3" fill="var(--accent-100)"/>
              <rect x="44" y="44" width="18" height="14" rx="3" fill="var(--accent-100)"/>
              <rect x="66" y="44" width="18" height="14" rx="3" fill="var(--accent)" opacity=".4"/>
              <rect x="22" y="62" width="18" height="14" rx="3" fill="var(--accent-100)"/>
            </svg>
          </div>
          <div className="appt__empty-copy">
            <strong>{L.upcomingEmpty}</strong>
            <span>{L.upcomingEmptyHint}</span>
          </div>
          <button className="btn btn--primary" onClick={onBook} disabled={booking}>
            {booking ? (<><span className="spinner" aria-hidden="true"/> {L.booking}</>) : (<><IconPlus size={16}/> {L.bookNow}</>)}
          </button>
        </div>
      </Bento>
    );
  }
  return (
    <Bento className="appt">
      <div className="card-head">
        <h3>{L.upcoming}</h3>
        <button className="btn btn--icon" aria-label={L.addToCal} title={L.addToCal}><IconCalendarPlus size={16}/></button>
      </div>
      <div className="appt__body">
        <div className="appt__date">
          <div className="appt__mo">MAY</div>
          <div className="appt__d">09</div>
          <div className="appt__dow">Friday</div>
        </div>
        <div className="appt__info">
          <div className="appt__service">General Medicine · Follow-up</div>
          <div className="appt__doc">Dr. Siriporn Ch. · Clinic A, Room 204</div>
          <div className="appt__row">
            <span className="chip chip--accent"><IconClock size={13}/> 10:30 – 11:00</span>
            <span className="chip"><IconMapPin size={13}/> RSU Medical Bldg.</span>
            <span className="chip"><IconStethoscope size={13}/> Booking #A-48201</span>
          </div>
        </div>
      </div>
      <div className="appt__actions">
        <button className="btn btn--ghost btn--danger-hover" onClick={onCancel}>
          <IconX size={15}/> {L.cancel}
        </button>
        <button className="btn btn--ghost">
          <IconCalendarPlus size={15}/> {L.addToCal}
        </button>
      </div>
    </Bento>
  );
}

function InsuranceCard({ L, status = 'active' }) {
  const active = status === 'active';
  return (
    <Bento className="wallet">
      <div className="wallet__top">
        <div className="wallet__brand">
          <IconShield size={18}/>
          <span>RSU Accident Care</span>
        </div>
        <span className={`pill ${active ? 'pill--ok' : 'pill--danger'}`}>
          <span className="dot"/>{active ? L.active : L.expired}
        </span>
      </div>
      <div className="wallet__label">{L.remaining}</div>
      <div className="wallet__amount">
        <span className="wallet__currency">฿</span>
        <span className="wallet__num">48,250</span>
        <span className="wallet__per">/ 100,000</span>
      </div>
      <div className="wallet__progress"><span style={{ width: '48%' }}/></div>
      <div className="wallet__foot">
        <div className="wallet__foot-col">
          <small>Policy</small>
          <span>RSU-24-U-0815</span>
        </div>
        <div className="wallet__foot-col">
          <small>Coverage</small>
          <span>{L.coverage.replace('Coverage until ','')}</span>
        </div>
        <IconChevronRight size={16} />
      </div>
      <div className="wallet__deco" aria-hidden="true">
        <svg viewBox="0 0 300 180" preserveAspectRatio="xMidYMid slice">
          <defs>
            <linearGradient id="wg" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0" stopColor="#fff" stopOpacity=".25"/>
              <stop offset="1" stopColor="#fff" stopOpacity="0"/>
            </linearGradient>
          </defs>
          <circle cx="260" cy="30" r="80" fill="url(#wg)"/>
          <circle cx="280" cy="140" r="40" fill="#fff" opacity=".08"/>
          <path d="M-20 140 Q 80 60 200 110 T 340 90" stroke="#fff" strokeOpacity=".18" fill="none" strokeWidth="1.5"/>
        </svg>
      </div>
    </Bento>
  );
}

function QuickActions({ L }) {
  const items = [
    { Ic: IconCalendarPlus, t: L.bookNew,  s: L.bookNewSub,  key: 'book',    tone: 'accent' },
    { Ic: IconHistory,      t: L.history,  s: L.historySub,  key: 'history', tone: 'violet' },
    { Ic: IconClipboard,    t: L.records,  s: L.recordsSub,  key: 'records', tone: 'teal' },
    { Ic: IconSettings,     t: L.settings, s: L.settingsSub, key: 'set',     tone: 'slate' },
  ];
  return (
    <Bento className="qa">
      <div className="card-head"><h3>{L.quickActions}</h3></div>
      <div className="qa__grid">
        {items.map(({ Ic, t, s, key, tone }) => (
          <button key={key} className={`qa__item qa__item--${tone}`}>
            <span className="qa__ic"><Ic size={20}/></span>
            <span className="qa__txt">
              <strong>{t}</strong>
              <small>{s}</small>
            </span>
            <IconArrowRight size={15} className="qa__arrow"/>
          </button>
        ))}
      </div>
    </Bento>
  );
}

function NotificationsCard({ L }) {
  const toneMap = { info: 'accent', ok: 'ok', warn: 'warn' };
  return (
    <Bento className="notif">
      <div className="card-head">
        <h3>{L.notifications} <span className="count">3</span></h3>
        <button className="link">{L.viewAll}</button>
      </div>
      <ul className="notif__list">
        {L.notifItems.map((n, i) => (
          <li key={i} className={`notif__item notif__item--${toneMap[n.kind]}`}>
            <span className="notif__dot"/>
            <div className="notif__body">
              <div className="notif__row"><strong>{n.t}</strong><time>{n.time}</time></div>
              <p>{n.b}</p>
            </div>
          </li>
        ))}
      </ul>
    </Bento>
  );
}

function CampaignsCard({ L }) {
  const rows = [
    { Ic: IconSyringe,      t: L.hpv, d: L.hpvDate, tone: 'accent' },
    { Ic: IconPill,         t: L.flu, d: L.fluDate, tone: 'teal' },
    { Ic: IconHeart,        t: L.eye, d: L.eyeDate, tone: 'violet' },
  ];
  return (
    <Bento className="camp">
      <div className="card-head"><h3>{L.campaigns}</h3><button className="link">{L.viewAll}</button></div>
      <ul className="camp__list">
        {rows.map((r, i) => (
          <li key={i} className={`camp__row camp__row--${r.tone}`}>
            <span className="camp__ic"><r.Ic size={16}/></span>
            <div className="camp__txt">
              <strong>{r.t}</strong>
              <small>{r.d}</small>
            </div>
            <IconChevronRight size={15}/>
          </li>
        ))}
      </ul>
    </Bento>
  );
}

function WellnessCard({ L }) {
  return (
    <Bento className="wellness">
      <div className="wellness__ic"><IconSparkles size={18}/></div>
      <div className="wellness__body">
        <small>{L.wellness}</small>
        <strong>{L.wellnessBody}</strong>
      </div>
    </Bento>
  );
}

function StatsStrip({ L }) {
  const stats = [
    { k: 'Next', v: 'May 9', sub: 'Follow-up' },
    { k: 'Visits YTD', v: '4', sub: '+1 vs 2024' },
    { k: 'Open Rx', v: '2', sub: 'Refill 1' },
    { k: 'Balance', v: '฿48,250', sub: '48% of cap' },
  ];
  return (
    <Bento className="stats">
      {stats.map((s, i) => (
        <div key={i} className="stat">
          <small>{s.k}</small>
          <strong>{s.v}</strong>
          <span>{s.sub}</span>
        </div>
      ))}
    </Bento>
  );
}

// ───────── top bar ─────────
function TopBar({ L, accent, lang, onLangToggle }) {
  return (
    <header className="topbar">
      <div className="topbar__brand">
        <IconLogo size={28} accent="var(--accent)"/>
        <div className="topbar__brand-txt">
          <strong>RSU Medical</strong>
          <small>User Hub</small>
        </div>
      </div>
      <nav className="topbar__nav" aria-label="Primary">
        <a className="is-active" href="#">{L.home}</a>
        <a href="#">{L.services}</a>
        <a href="#">{L.appointments}</a>
        <a href="#">{L.help}</a>
      </nav>
      <div className="topbar__search">
        <IconSearch size={16}/>
        <input placeholder={L.search}/>
        <KBD>⌘K</KBD>
      </div>
      <div className="topbar__tools">
        <button className="btn btn--icon" onClick={onLangToggle} aria-label="Toggle language"><span style={{fontWeight:600,fontSize:11}}>{lang === 'th' ? 'TH' : 'EN'}</span></button>
        <button className="btn btn--icon" aria-label="Notifications"><IconBell size={18}/><span className="topbar__ping"/></button>
        <button className="avatar avatar--sm" aria-label="Account menu">
          <svg width="32" height="32" viewBox="0 0 32 32"><rect width="32" height="32" rx="10" fill="var(--accent-100)"/><text x="16" y="21" textAnchor="middle" fontFamily="Prompt,Inter" fontWeight="600" fontSize="12" fill="var(--accent-600)">TP</text></svg>
        </button>
      </div>
    </header>
  );
}

// ───────── full hub (bento desktop layout) ─────────
function UserHubDesktop({ lang = 'en', density = 'regular', apptState = 'booked', status = 'active' }) {
  const L = HUB_COPY[lang];
  const [booking, setBooking] = React.useState(false);
  const onBook = () => { setBooking(true); setTimeout(() => setBooking(false), 1800); };
  return (
    <div className={`hub hub--${density}`}>
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

Object.assign(window, {
  HUB_COPY, USER, Bento, Badge, KBD,
  ProfileCard, AppointmentCard, InsuranceCard, QuickActions, NotificationsCard, CampaignsCard, WellnessCard, StatsStrip,
  TopBar, UserHubDesktop,
});
