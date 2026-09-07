import React, {useEffect, useRef, useState} from 'react'
import {createRoot} from 'react-dom/client'
import './styles.css'

const API = (import.meta.env.VITE_API_URL || 'http://localhost:8000/api/v1').replace(/\/$/, '')
const emptyData = {patients: [], rooms: [], devices: [], events: [], readings: [], assignments: [], caregivers: []}
const fmt = value => value ? new Date(value).toLocaleString([], {month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'}) : 'Not recorded'
const label = value => (value || '').replaceAll('_', ' ').replace(/^./, c => c.toUpperCase())
const initials = name => (name || '?').split(' ').map(n => n[0]).slice(0, 2).join('')
const eventName = e => e.event_type === 'manual_emergency' ? 'Manual emergency' : 'Possible fall detected'
const magnitude = (reading, prefix) => Math.hypot(...['x', 'y', 'z'].map(axis => reading[`${prefix}_${axis}`]))

async function request(path, token, options = {}) {
  let response
  try {
    response = await fetch(API + path, {...options, headers: {'Content-Type': 'application/json', ...(token ? {Authorization: `Bearer ${token}`} : {}), ...options.headers}})
  } catch { throw new Error('Cannot reach the API. Start the backend and try again.') }
  const body = await response.json().catch(() => null)
  if (!response.ok) {
    const detail = body?.detail
    const error = new Error(typeof detail === 'string' ? detail : Array.isArray(detail) ? detail.map(e => `${e.loc.at(-1)}: ${e.msg}`).join('; ') : `Request failed (${response.status}).`)
    error.status = response.status
    throw error
  }
  return body
}

async function allPages(path, token) {
  const items = []
  for (let page = 1; ; page++) {
    const result = await request(`${path}?page=${page}&page_size=100`, token)
    items.push(...result.items)
    if (items.length >= result.total || !result.items.length) return items
  }
}

function Icon({name = 'grid', size = 19}) {
  const paths = {grid: 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z', user: 'M20 21a8 8 0 0 0-16 0M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z', room: 'M3 21V5l9-3 9 3v16M8 21v-5h8v5M8 8h1M15 8h1M8 12h1M15 12h1', device: 'M7 2h10v20H7zM10 5h4M11 18h2', event: 'M18 8A6 6 0 0 0 6 8c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4', settings: 'M12 8v8M8 12h8M21 12a9 9 0 1 0-18 0 9 9 0 0 0 18 0', arrow: 'M5 12h14m-6-6 6 6-6 6', refresh: 'M20 7v5h-5M4 17v-5h5M6 7a7 7 0 0 1 12-1l2 6M4 12l2 6a7 7 0 0 0 12-1'}
  return <svg aria-hidden="true" width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d={paths[name] || paths.grid}/></svg>
}
const Brand = () => <div className="brand"><div className="brand-mark">✦</div><div><b>carewatch</b><span>monitoring workspace</span></div></div>
const Badge = ({value}) => <span className={`status-pill ${value}`}>{label(value)}</span>
const Empty = ({title, children}) => <div className="empty"><div className="empty-icon"><Icon name="event" size={28}/></div><h3>{title}</h3><p>{children}</p></div>
const PageTitle = ({kicker, title, sub, action}) => <div className="page-title inner"><div><span className="eyebrow">{kicker}</span><h1>{title}</h1><p>{sub}</p></div>{action}</div>

function App() {
  const [token, setToken] = useState(() => sessionStorage.getItem('cw_token'))
  const [user, setUser] = useState(null)
  const [data, setData] = useState(emptyData)
  const [page, setPage] = useState('overview')
  const [selected, setSelected] = useState(null)
  const [menu, setMenu] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [synced, setSynced] = useState(null)
  const [modal, setModal] = useState(null)
  const [toast, setToast] = useState('')
  const toastTimer = useRef()
  const generation = useRef(0)
  const notify = message => {clearTimeout(toastTimer.current); setToast(message); toastTimer.current = setTimeout(() => setToast(''), 5000)}
  useEffect(() => () => clearTimeout(toastTimer.current), [])
  const logout = () => {generation.current++; sessionStorage.removeItem('cw_token'); localStorage.removeItem('cw_token'); setToken(null); setUser(null); setData(emptyData); setSynced(null); setPage('overview'); setModal(null); setError('')}
  const api = async (path, options) => {
    try { return await request(path, token, options) }
    catch (e) { if (e.status === 401) logout(); throw e }
  }
  const refresh = async () => {
    const current = ++generation.current
    setBusy(true); setError('')
    try {
      const me = await request('/auth/me', token)
      const [patients, rooms, devices, events, readings, assignments, caregivers] = await Promise.all([
        allPages('/patients', token), request('/rooms', token), request('/devices', token), allPages('/events', token), request('/sensor-readings?page_size=100', token), request('/device-assignments', token), me.role === 'administrator' ? request('/caregivers', token) : Promise.resolve([])
      ])
      if (current !== generation.current) return
      setUser(me); setData({patients, rooms: rooms.items, devices: devices.items, events, readings: readings.items, assignments: assignments.items, caregivers}); setSynced(new Date())
    } catch (e) {
      if (current !== generation.current) return
      if (e.status === 401) logout(); else setError(e.message)
    } finally {if (current === generation.current) setBusy(false)}
  }
  useEffect(() => {if (token) refresh(); return () => {generation.current++}}, [token])
  const nav = (next, id = null) => {setPage(next); setSelected(id); setMenu(false)}
  const saved = async message => {setModal(null); notify(message); await refresh()}
  if (!token) return <Login onLogin={value => {sessionStorage.setItem('cw_token', value); setToken(value)}}/>
  const admin = user?.role === 'administrator'
  const shared = {data, user, nav, api, notify, refresh, open: setModal}
  return <div className="shell">
    <aside className={menu ? 'open' : ''}><Brand/><div className="workspace">WORKSPACE <span>LOCAL DEMO</span></div><nav aria-label="Main navigation">{[['overview', 'Overview', 'grid'], ['patients', 'Patients', 'user'], ['rooms', 'Rooms', 'room'], ['devices', 'Devices', 'device'], ['events', 'Event history', 'event'], ['admin', 'Workspace', 'settings']].map(([id, text, icon]) => <button key={id} className={page === id || (id === 'events' && page === 'event-detail') ? 'active' : ''} onClick={() => nav(id)}><Icon name={icon}/><span>{text}</span>{id === 'events' && !!data.events.filter(e => e.status === 'pending').length && <em>{data.events.filter(e => e.status === 'pending').length}</em>}</button>)}</nav><div className="side-foot"><div className="sim-dot"/>Simulation environment<p className="muted">No physical devices connected</p><button className="outline full" onClick={logout}>Sign out</button></div></aside>
    <main><header><button className="mobile-menu" aria-label="Toggle navigation" aria-expanded={menu} onClick={() => setMenu(!menu)}>☰</button><div className="crumb">Workspace <span>/ {page === 'event-detail' ? 'Event detail' : label(page === 'admin' ? 'settings' : page)}</span></div><div className="header-actions"><button className="text-btn sync" onClick={refresh} disabled={busy}><Icon name="refresh" size={15}/>{busy ? 'Refreshing…' : synced ? `Synced ${fmt(synced)}` : 'Refresh'}</button><div className="profile"><div className="avatar">{initials(user?.full_name)}</div><span>{user?.full_name || 'Connecting…'}</span><small>{label(user?.role)}</small></div></div></header>
      <div className="content"><div className="sim-banner"><span>SIMULATED ENVIRONMENT</span><label>Generated readings and alerts for local development. No physical sensors or cameras are connected.</label><button onClick={() => setModal({type: 'simulation'})} disabled={!synced || !data.patients.length}>Simulation controls ↗</button></div>
        {error && <div className="error" role="alert">{error} <button className="text-btn" onClick={refresh}>Retry</button>{synced && <p>Showing the last successfully loaded records.</p>}</div>}
        {!synced ? <div className="loading" role="status">{busy ? 'Loading your workspace…' : 'The workspace could not be loaded. Use Retry to reconnect.'}</div> : page === 'overview' ? <Overview {...shared}/> : page === 'patients' ? <Patients {...shared}/> : page === 'rooms' ? <Rooms {...shared}/> : page === 'devices' ? <Devices {...shared}/> : page === 'events' ? <Events {...shared}/> : page === 'event-detail' ? <EventDetail key={selected} {...shared} eventId={selected}/> : <Workspace user={user} data={data} admin={admin}/>}</div>
    </main>{toast && <div className="toast" role="status">✓ {toast}</div>}{modal && <Editor key={`${modal.type}-${modal.record?.id || ''}`} modal={modal} {...shared} onClose={() => setModal(null)} onSaved={saved}/>}</div>
}

function Login({onLogin}) {
  const [email, setEmail] = useState('admin@example.com'), [password, setPassword] = useState(''), [show, setShow] = useState(false), [loading, setLoading] = useState(false), [error, setError] = useState('')
  const submit = async e => {e.preventDefault(); setLoading(true); setError(''); try {const result = await request('/auth/login', null, {method: 'POST', body: JSON.stringify({email, password})}); onLogin(result.access_token)} catch (e) {setError(e.message)} finally {setLoading(false)}}
  return <div className="login"><div className="login-art"><div className="art-orb orb1"/><div className="art-orb orb2"/><div className="art-grid"/><div className="login-quote">✦<br/><span>Good care starts<br/>with good visibility.</span><small>CareWatch · local monitoring prototype</small></div></div><div className="login-panel"><Brand/><div className="login-copy"><span className="eyebrow">YOUR CARE WORKSPACE</span><h1>Welcome back</h1><p>Sign in to review patient activity and simulated alerts.</p></div><form onSubmit={submit}><label>Work email<input type="email" autoComplete="username" value={email} onChange={e => setEmail(e.target.value)} required/></label><label htmlFor="login-password">Password</label><div className="pass"><input id="login-password" type={show ? 'text' : 'password'} autoComplete="current-password" value={password} onChange={e => setPassword(e.target.value)} required/><button type="button" aria-label={show ? 'Hide password' : 'Show password'} onClick={() => setShow(!show)}>{show ? 'Hide' : 'Show'}</button></div>{error && <div className="error" role="alert">{error}</div>}<button className="primary full" disabled={loading}>{loading ? 'Signing in…' : 'Sign in'} <Icon name="arrow" size={17}/></button></form><div className="login-note"><b>Local development only</b><p>Default demo password: <code>AdminDemo123!</code><br/>Use the configured seed password if it was changed.</p><small>All seeded patient records and device readings are synthetic.</small></div></div></div>
}

const Stat = ({label: title, value, sub, tone, iconName}) => <div className="stat"><div className={`stat-icon ${tone}`}><Icon name={iconName}/></div><div><small>{title}</small><strong>{String(value).padStart(2, '0')}</strong><span>{sub}</span></div></div>
function Overview({data, nav, open}) {
  const patient = data.patients[0], room = data.rooms.find(r => r.id === patient?.room_id)
  const pending = data.events.filter(e => e.status === 'pending').length
  return <><PageTitle kicker={new Date().toLocaleDateString([], {weekday: 'long', month: 'long', day: 'numeric', year: 'numeric'})} title="Your care overview" sub="Patient activity and alerts, together in one place." action={<button className="outline" disabled={!data.patients.length} onClick={() => open({type: 'simulation'})}>＋ Simulate event</button>}/><div className="stats"><Stat label="Patients" value={data.patients.length} sub="In your workspace" tone="teal" iconName="user"/><Stat label="Registered devices" value={data.devices.length} sub={`${data.devices.filter(d => d.status === 'simulated').length} simulated`} tone="blue" iconName="device"/><Stat label="Open alerts" value={pending} sub="Pending review" tone="amber" iconName="event"/><Stat label="Events today" value={data.events.filter(e => new Date(e.occurred_at).toDateString() === new Date().toDateString()).length} sub="Based on local time" tone="purple" iconName="grid"/></div>
    <div className="grid-2 main-grid"><section className="card patient-card"><div className="card-head"><div><span className="eyebrow">PATIENT SPOTLIGHT</span><h2>{patient?.full_name || 'No patients yet'}</h2></div>{patient?.is_synthetic && <Badge value="synthetic"/>}</div>{patient ? <><div className="patient-body"><div className="patient-avatar">{initials(patient.full_name)}</div><div className="patient-details"><p>{patient.age != null ? `${patient.age} years` : 'Age not recorded'}</p><span>{room?.name || 'No room assigned'}</span><p className="muted">{patient.notes || 'No care notes recorded.'}</p></div></div><div className="patient-foot"><span>{data.assignments.filter(a => a.patient_id === patient.id).length} assigned devices</span><button className="text-btn" onClick={() => nav('patients')}>View patients <Icon name="arrow" size={15}/></button></div></> : <Empty title="Start with a patient">Add a patient to organize room and device assignments.</Empty>}</section><section className="card alerts-card"><div className="card-head"><div><span className="eyebrow">LATEST ACTIVITY</span><h2>Recent events</h2></div><button className="text-btn" onClick={() => nav('events')}>View all <Icon name="arrow" size={15}/></button></div>{data.events.length ? data.events.slice(0, 3).map(e => <button className="alert-row full" key={e.id} onClick={() => nav('event-detail', e.id)}><div className={`alert-icon ${e.event_type === 'manual_emergency' ? 'red' : 'amber'}`}>{e.event_type === 'manual_emergency' ? '!' : <Icon name="event"/>}</div><div><b>{eventName(e)}</b><small>{data.patients.find(p => p.id === e.patient_id)?.full_name || 'Patient'} · {fmt(e.occurred_at)}</small></div><Badge value={e.status}/></button>) : <Empty title="No events recorded">Create a simulated event to try the review workflow.</Empty>}</section></div>
    <section className="card chart-card"><div className="card-head"><div><span className="eyebrow">SENSOR HISTORY</span><h2>Recent acceleration</h2></div><span className="muted">Latest {data.readings.length} readings · all visible patients</span></div><MovementChart readings={data.readings}/></section></>
}

function MovementChart({readings}) {
  if (!readings.length) return <Empty title="No sensor readings">Generate normal motion or a possible fall from Simulation controls.</Empty>
  const values = [...readings].sort((a, b) => new Date(a.recorded_at) - new Date(b.recorded_at))
  const max = Math.max(1, ...values.map(r => magnitude(r, 'acceleration')))
  const coords = values.map((r, i) => [20 + (values.length === 1 ? 280 : i * 560 / (values.length - 1)), 155 - magnitude(r, 'acceleration') / max * 130])
  return <div className="chart"><svg viewBox="0 0 600 185" role="img" aria-label={`Acceleration magnitude across ${values.length} readings. Maximum ${max.toFixed(2)} in sensor units.`}><path d="M20 25H580M20 90H580M20 155H580" stroke="#e7eeef" fill="none"/><polyline points={coords.map(p => p.join(',')).join(' ')} fill="none" stroke="#0d9e8d" strokeWidth="3"/>{coords.map(([x, y], i) => <circle key={values[i].id} cx={x} cy={y} r={values.length < 12 ? 4 : 2} fill="#0d9e8d"><title>{fmt(values[i].recorded_at)}: {magnitude(values[i], 'acceleration').toFixed(2)}</title></circle>)}</svg><div className="xlabels"><span>{fmt(values[0].recorded_at)}</span><span>Magnitude · sensor units</span><span>{fmt(values.at(-1).recorded_at)}</span></div></div>
}

function Patients({data, user, open}) {
  return <><PageTitle kicker="PATIENTS" title="Patient information" sub="Room assignments, care notes, and recent sensor activity." action={user.role === 'administrator' && <button className="primary" onClick={() => open({type: 'patient'})}>＋ Add patient</button>}/>{!data.patients.length ? <section className="card"><Empty title="No patients in your workspace">An administrator can add a patient or assign one to your account.</Empty></section> : data.patients.map(p => {const room = data.rooms.find(r => r.id === p.room_id), reading = data.readings.find(r => r.patient_id === p.id); return <section className="card patient-card" key={p.id}><div className="card-head"><div className="patient-body"><div className="patient-avatar">{initials(p.full_name)}</div><div><h2>{p.full_name}</h2><p className="muted">{p.age != null ? `${p.age} years` : 'Age not recorded'} · {room?.name || 'No room assigned'}</p></div></div><div className="table-actions">{p.is_synthetic && <Badge value="synthetic"/>}{user.role === 'administrator' && <button className="outline" onClick={() => open({type: 'patient', record: p})}>Edit patient</button>}</div></div><div className="details-list"><div><span>Care notes</span><b>{p.notes || 'Not recorded'}</b></div><div><span>Emergency contact</span><b>{p.emergency_contact || 'Not recorded'}</b></div><div><span>Device assignments</span><b>{data.assignments.filter(a => a.patient_id === p.id).length}</b></div><div><span>Latest loaded reading</span><b>{fmt(reading?.recorded_at)}</b></div></div>{reading && <ReadingDetails reading={reading}/>}</section>})}</>
}

function Rooms({data, user, open}) {
  return <><PageTitle kicker="ROOMS" title="Room management" sub="Patient assignments across the workspace." action={user.role === 'administrator' && <button className="primary" onClick={() => open({type: 'room'})}>＋ Add room</button>}/><div className="room-list">{data.rooms.length ? data.rooms.map(r => {const patient = data.patients.find(p => p.room_id === r.id); return <section className="card room-row" key={r.id}><div className="room-number"><Icon name="room" size={30}/></div><div className="room-info"><h2>{r.name}</h2><p>{r.floor ? `Floor ${r.floor}` : 'Floor not recorded'} · {r.description || 'No description'}</p><div className="room-patient"><div className="mini-avatar">{patient ? initials(patient.full_name) : '–'}</div><span><b>{patient?.full_name || 'No visible patient assigned'}</b><small>{patient ? 'Current patient' : 'Room assignment'}</small></span></div></div>{r.is_synthetic && <Badge value="synthetic"/>}{user.role === 'administrator' && <button className="outline" onClick={() => open({type: 'room', record: r})}>Edit room</button>}</section>}) : <section className="card"><Empty title="No rooms available">Add a room to organize patient assignments.</Empty></section>}</div></>
}

function Devices({data, user, open}) {
  const [search, setSearch] = useState('')
  const shown = data.devices.filter(d => `${d.name} ${d.serial_number}`.toLowerCase().includes(search.toLowerCase()))
  return <><PageTitle kicker="DEVICES" title="Device management" sub="Registered equipment and patient assignments." action={user.role === 'administrator' && <button className="primary" onClick={() => open({type: 'device'})}>＋ Register device</button>}/><div className="device-banner"><Icon name="device" size={25}/><div><b>Hardware integration is reserved for a later phase</b><p>Device records support local simulation. Registration does not connect physical equipment.</p></div><Badge value="simulated"/></div><section className="card table-card"><div className="table-head"><span>{shown.length} of {data.devices.length} devices</span><input aria-label="Search devices" placeholder="Search devices…" value={search} onChange={e => setSearch(e.target.value)}/></div><div className="table-scroll"><table><thead><tr><th>Device</th><th>Type</th><th>Patient</th><th>Status</th><th>Last seen</th>{user.role === 'administrator' && <th>Assignment</th>}</tr></thead><tbody>{shown.map(d => {const assignment = data.assignments.find(a => a.device_id === d.id); return <tr key={d.id}><td><div className="device-name"><span className="device-symbol"><Icon name="device"/></span><span><b>{d.name}</b><small>{d.serial_number}</small></span></div></td><td>{d.device_type === 'esp32_cam' ? 'ESP32-CAM' : 'ESP32 DevKit V1'}</td><td>{data.patients.find(p => p.id === assignment?.patient_id)?.full_name || 'Unassigned'}</td><td><Badge value={d.status}/></td><td>{fmt(d.last_seen_at)}</td>{user.role === 'administrator' && <td>{assignment ? <span className="muted">Assigned</span> : <button className="outline small" disabled={!data.patients.length} onClick={() => open({type: 'assignment', record: d})}>Assign patient</button>}</td>}</tr>})}</tbody></table></div>{!shown.length && <Empty title="No matching devices">Register a device or change your search.</Empty>}</section></>
}

function Events({data, nav, open}) {
  const [status, setStatus] = useState('all'), [type, setType] = useState('all'), [date, setDate] = useState('')
  const shown = data.events.filter(e => (status === 'all' || e.status === status) && (type === 'all' || e.event_type === type) && (!date || new Date(e.occurred_at).toLocaleDateString('en-CA') === date))
  return <><PageTitle kicker="EVENT HISTORY" title="Events" sub="Review alerts and record the outcome of each incident." action={<button className="outline" disabled={!data.patients.length} onClick={() => open({type: 'simulation'})}>＋ Simulate event</button>}/><div className="event-toolbar"><div className="filter-tabs" aria-label="Event status">{['all', 'pending', 'acknowledged', 'resolved', 'false_alarm'].map(s => <button className={s === status ? 'active' : ''} key={s} onClick={() => setStatus(s)}>{s === 'all' ? 'All events' : label(s)} <span>{s === 'all' ? data.events.length : data.events.filter(e => e.status === s).length}</span></button>)}</div><select aria-label="Event type" value={type} onChange={e => setType(e.target.value)}><option value="all">All event types</option><option value="possible_fall">Possible fall</option><option value="manual_emergency">Manual emergency</option></select><input type="date" aria-label="Event date" value={date} onChange={e => setDate(e.target.value)}/>{date && <button className="text-btn" onClick={() => setDate('')}>Clear date</button>}</div><section className="card table-card"><div className="table-scroll"><table><thead><tr><th>Event</th><th>Patient</th><th>Detected</th><th>Source</th><th>Status</th><th>Review</th></tr></thead><tbody>{shown.map(e => <tr key={e.id}><td><div className="event-name"><span className={`alert-icon ${e.event_type === 'manual_emergency' ? 'red' : 'amber'}`}>{e.event_type === 'manual_emergency' ? '!' : <Icon name="event"/>}</span><span><b>{eventName(e)}</b><small title={e.id}>{e.id.slice(0, 8)}</small></span></div></td><td>{data.patients.find(p => p.id === e.patient_id)?.full_name || 'Patient unavailable'}</td><td>{fmt(e.occurred_at)}</td><td><Badge value={e.is_simulated ? 'simulated' : 'recorded'}/></td><td><Badge value={e.status}/></td><td><button className="text-btn" aria-label={`Review event ${e.id.slice(0, 8)}`} onClick={() => nav('event-detail', e.id)}>Review <Icon name="arrow" size={15}/></button></td></tr>)}</tbody></table></div>{!shown.length && <Empty title="No matching events">Generate a simulated alert or change your filters.</Empty>}</section></>
}

function ReadingDetails({reading}) {
  return <div className="reading-grid">{['acceleration', 'angular_velocity'].map(prefix => <div className="reading" key={prefix}><small>{label(prefix)} · raw sensor axes</small><b>{magnitude(reading, prefix).toFixed(2)} <i>magnitude</i></b><p>{['x', 'y', 'z'].map(axis => `${axis.toUpperCase()}: ${reading[`${prefix}_${axis}`].toFixed(2)}`).join(' · ')}</p><span>{reading.is_simulated ? 'Simulated reading' : 'Recorded reading'}</span></div>)}</div>
}

function EventDetail({eventId, data, nav, api, notify, refresh}) {
  const [event, setEvent] = useState(null), [cameras, setCameras] = useState([]), [reading, setReading] = useState(null), [notes, setNotes] = useState(''), [loading, setLoading] = useState(true), [saving, setSaving] = useState(false), [error, setError] = useState('')
  const load = async () => {setLoading(true); setError(''); try {const item = await api(`/events/${eventId}`); const [cameraData, readingData] = await Promise.all([api(`/events/${eventId}/camera-verifications`), item.sensor_reading_id ? api(`/sensor-readings?id=${item.sensor_reading_id}`) : Promise.resolve({items: []})]); setEvent(item); setNotes(item.notes || ''); setCameras(cameraData.items); setReading(readingData.items[0] || null)} catch (e) {setError(e.message)} finally {setLoading(false)}}
  useEffect(() => {load()}, [eventId])
  const save = async status => {setSaving(true); setError(''); try {const item = await api(`/events/${eventId}/status`, {method: 'PATCH', body: JSON.stringify({status, notes})}); setEvent(item); setNotes(item.notes || ''); notify('Event review saved'); await refresh()} catch (e) {setError(e.message)} finally {setSaving(false)}}
  const patient = data.patients.find(p => p.id === event?.patient_id), room = data.rooms.find(r => r.id === patient?.room_id), device = data.devices.find(d => d.id === event?.device_id)
  return <><button className="back-btn" onClick={() => nav('events')}>← Back to event history</button>{error && <div className="error" role="alert">{error} {!event && <button className="text-btn" onClick={load}>Retry</button>}</div>}{loading ? <div className="loading">Loading event…</div> : event && <><PageTitle kicker="EVENT DETAIL" title={eventName(event)} sub={`${event.id} · ${fmt(event.occurred_at)}`}/><div className="detail-grid"><section className="card detail-main"><div className="detail-alert"><span className="alert-icon amber"><Icon name="event"/></span><div><b>{eventName(event)}</b><small>{patient?.full_name || 'Patient unavailable'} · {room?.name || 'No room assigned'}</small></div><Badge value={event.status}/></div><div className="detail-section"><span className="eyebrow">CAMERA VERIFICATION</span>{cameras.length ? cameras.map(c => <div className="camera-placeholder" key={c.id}><Icon name="device" size={30}/><div><Badge value={c.status}/><p>{c.result}</p><small>{c.is_simulated ? 'Simulated camera metadata · no snapshot' : 'Camera metadata'} · {fmt(c.created_at)}</small></div></div>) : <Empty title="No camera verification recorded">{event.event_type === 'manual_emergency' ? 'This manual emergency has no camera response.' : 'No camera response is available for this event.'}</Empty>}</div><div className="detail-section"><span className="eyebrow">EVENT CONTEXT</span>{reading ? <ReadingDetails reading={reading}/> : <p className="muted">No sensor reading attached to this event.</p>}<div className="details-list"><div><span>Detection confidence</span><b>{event.confidence == null ? 'Not provided' : `${Math.round(event.confidence * 100)}%`}</b></div><div><span>Source</span><b>{event.is_simulated ? 'Simulation' : 'Recorded event'}</b></div></div></div><form className="detail-section" onSubmit={e => {e.preventDefault(); save(event.status)}}><label htmlFor="incident-notes" className="eyebrow">INCIDENT NOTE</label><textarea id="incident-notes" value={notes} onChange={e => setNotes(e.target.value)} placeholder="Record the outcome of your review…"/><button className="primary small" disabled={saving}>{saving ? 'Saving…' : 'Save note'}</button></form></section><section className="detail-side card"><span className="eyebrow">UPDATE STATUS</span><h3>Record your review</h3><p>Save the current note with a new event status.</p>{['pending', 'acknowledged', 'resolved', 'false_alarm'].map(s => <button disabled={saving || s === event.status} key={s} className={`status-choice ${event.status === s ? 'chosen' : ''}`} onClick={() => save(s)}><span>{event.status === s ? '✓' : '○'}</span>{label(s)}</button>)}<div className="detail-meta"><div><span>Patient</span><b>{patient?.full_name || 'Unavailable'}</b></div><div><span>Room</span><b>{room?.name || 'Unassigned'}</b></div><div><span>Device</span><b>{device?.name || event.device_id}</b></div><div><span>Last updated</span><b>{fmt(event.updated_at)}</b></div></div></section></div></>}</>
}

function Workspace({user, data, admin}) {
  return <><PageTitle kicker="WORKSPACE" title="Workspace information" sub="Account access and the boundaries of this local prototype."/><div className="grid-2"><section className="card"><div className="card-head"><div><span className="eyebrow">YOUR ACCOUNT</span><h2>{user.full_name}</h2></div><Icon name="user"/></div><div className="details-list"><div><span>Email</span><b>{user.email}</b></div><div><span>Role</span><b>{label(user.role)}</b></div><div><span>Access</span><b>{admin ? 'Manage patients, rooms, devices, and events' : 'Review assigned patients and events'}</b></div></div></section><section className="card"><div className="card-head"><div><span className="eyebrow">LOCAL ENVIRONMENT</span><h2>Simulation mode</h2></div><Badge value="simulated"/></div><div className="details-list"><div><span>Physical ingestion</span><b>Not enabled</b></div><div><span>Camera images</span><b>No real images captured</b></div><div><span>External notifications</span><b>Not sent; simulated delivery records only</b></div><div><span>Visible records</span><b>{data.patients.length} patients · {data.events.length} events</b></div></div></section></div></>
}

function Editor({modal, data, api, onClose, onSaved}) {
  const {type, record} = modal
  const [draft, setDraft] = useState(() => ({full_name: record?.full_name || '', age: record?.age ?? '', room_id: record?.room_id || '', caregiver_id: record?.caregiver_id || '', notes: record?.notes || '', emergency_contact: record?.emergency_contact || '', name: record?.name || '', floor: record?.floor || '', description: record?.description || '', serial_number: '', device_type: 'esp32_devkit_v1', patient_id: data.patients[0]?.id || '', simulation: 'possible-fall'}))
  const [busy, setBusy] = useState(false), [error, setError] = useState(''), [key, setKey] = useState(null)
  const dialog = useRef(null)
  useEffect(() => {const previous = document.activeElement; dialog.current?.focus(); return () => previous?.focus()}, [])
  const change = (name, value) => setDraft(previous => ({...previous, [name]: value}))
  const titles = {patient: record ? 'Edit patient' : 'Add patient', room: record ? 'Edit room' : 'Add room', device: 'Register device', assignment: 'Assign a patient', simulation: 'Simulation controls'}
  const submit = async e => {
    e.preventDefault(); setBusy(true); setError('')
    try {
      if (type === 'patient') await api(`/patients${record ? `/${record.id}` : ''}`, {method: record ? 'PATCH' : 'POST', body: JSON.stringify({full_name: draft.full_name, age: draft.age === '' ? null : Number(draft.age), room_id: draft.room_id || null, caregiver_id: draft.caregiver_id || null, notes: draft.notes || null, emergency_contact: draft.emergency_contact || null})})
      if (type === 'room') await api(`/rooms${record ? `/${record.id}` : ''}`, {method: record ? 'PATCH' : 'POST', body: JSON.stringify({name: draft.name, floor: draft.floor || null, description: draft.description || null})})
      if (type === 'device') {const result = await api('/devices', {method: 'POST', body: JSON.stringify({name: draft.name, serial_number: draft.serial_number, device_type: draft.device_type, status: 'simulated'})}); setKey(result.device_key); return}
      if (type === 'assignment') await api(`/devices/${record.id}/assign`, {method: 'POST', body: JSON.stringify({patient_id: draft.patient_id})})
      if (type === 'simulation') {
        const idempotencyKey = globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(36).slice(2)}`
        await api(`/simulation/${draft.simulation}`, {method: 'POST', body: JSON.stringify({patient_id: draft.patient_id, idempotency_key: idempotencyKey})})
      }
      await onSaved(type === 'simulation' ? 'Simulation created' : type === 'assignment' ? 'Device assigned' : `${label(type)} saved`)
    } catch (e) {setError(e.message)} finally {setBusy(false)}
  }
  const field = (name, title, props = {}) => <label className="field">{title}<input value={draft[name]} onChange={e => change(name, e.target.value)} {...props}/></label>
  const patientSelect = <label className="field">Patient<select required value={draft.patient_id} onChange={e => change('patient_id', e.target.value)}><option value="">Choose a patient</option>{data.patients.map(p => <option value={p.id} key={p.id}>{p.full_name}</option>)}</select></label>
  const close = () => {if (!busy) key ? onSaved('Device registered') : onClose()}
  const trap = e => {if (e.key === 'Escape') {e.preventDefault(); close()} if (e.key === 'Tab') {const focusable = [...dialog.current.querySelectorAll('button, input, select, textarea, [tabindex="0"]')].filter(el => !el.disabled); const first = focusable[0], last = focusable.at(-1); if (e.shiftKey && (document.activeElement === first || document.activeElement === dialog.current)) {e.preventDefault(); last?.focus()} else if (!e.shiftKey && document.activeElement === last) {e.preventDefault(); first?.focus()}}}
  return <div className="modal-backdrop"><section className="modal" role="dialog" aria-modal="true" aria-labelledby="dialog-title" tabIndex={-1} ref={dialog} onKeyDown={trap}><div className="card-head"><h2 id="dialog-title">{titles[type]}</h2><button type="button" aria-label="Close dialog" className="icon-btn" disabled={busy} onClick={close}>×</button></div>{key ? <div className="detail-section"><h3>Device registered</h3><p>Save this key securely. It is shown only in this response; physical ingestion is not enabled.</p><label className="field">Device key<textarea readOnly value={key} onFocus={e => e.target.select()}/></label><button className="primary" onClick={() => onSaved('Device registered')}>Done</button></div> : <form onSubmit={submit}><div className="form-grid">
    {type === 'patient' && <>{field('full_name', 'Full name', {required: true, maxLength: 120})}{field('age', 'Age', {type: 'number', min: 0, max: 130})}<label className="field">Room<select value={draft.room_id} onChange={e => change('room_id', e.target.value)}><option value="">Unassigned</option>{data.rooms.filter(r => r.id === record?.room_id || !data.patients.some(p => p.room_id === r.id)).map(r => <option value={r.id} key={r.id}>{r.name}</option>)}</select></label><label className="field">Caregiver<select value={draft.caregiver_id} onChange={e => change('caregiver_id', e.target.value)}><option value="">Unassigned</option>{data.caregivers.map(c => <option value={c.id} key={c.id}>{c.full_name} · {c.email}</option>)}</select></label>{field('emergency_contact', 'Emergency contact', {maxLength: 200})}<label className="field">Care notes<textarea value={draft.notes} onChange={e => change('notes', e.target.value)}/></label></>}
    {type === 'room' && <>{field('name', 'Room name', {required: true, maxLength: 80})}{field('floor', 'Floor', {maxLength: 80})}{field('description', 'Description')}</>}
    {type === 'device' && <>{field('name', 'Device name', {required: true, maxLength: 120})}{field('serial_number', 'Serial number', {required: true, maxLength: 100})}<label className="field">Device type<select value={draft.device_type} onChange={e => change('device_type', e.target.value)}><option value="esp32_devkit_v1">ESP32 DevKit V1</option><option value="esp32_cam">ESP32-CAM</option></select></label><p className="muted">The registered record starts in simulated mode.</p></>}
    {type === 'assignment' && <><p>Assign <b>{record.name}</b> to a patient. Each patient can have one active device of each type.</p>{patientSelect}</>}
    {type === 'simulation' && <><p>Create synthetic readings or events for the selected patient’s assigned motion device.</p>{patientSelect}<label className="field">Scenario<select value={draft.simulation} onChange={e => change('simulation', e.target.value)}><option value="normal-motion">Normal motion reading</option><option value="possible-fall">Possible fall + simulated camera response</option><option value="manual-emergency">Manual emergency</option><option value="camera-response">Possible fall + camera completed</option><option value="camera-timeout">Possible fall + camera timeout</option></select></label><p className="muted">No physical devices are contacted or external notifications sent.</p></>}
    </div>{error && <div className="error" role="alert">{error}</div>}<div className="dialog-actions"><button type="button" className="outline" disabled={busy} onClick={onClose}>Cancel</button><button className="primary" disabled={busy}>{busy ? 'Saving…' : type === 'simulation' ? 'Run simulation' : 'Save'}</button></div></form>}</section></div>
}

createRoot(document.getElementById('root')).render(<App/>)
