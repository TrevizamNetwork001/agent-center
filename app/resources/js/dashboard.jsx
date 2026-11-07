import React, {useCallback, useEffect, useRef, useState} from 'react';
import {createRoot} from 'react-dom/client';
import '../css/dashboard.css';

const paths={pulse:'M3 12h4l3-8 4 16 3-8h4',search:'m21 21-4.4-4.4M19 11a8 8 0 1 1-16 0 8 8 0 0 1 16 0',agents:'M3 5h18v6H3zM3 15h18v5H3zM6 8h.01M6 18h.01',activity:'M8 3v4m8-4v4M3 11h18M5 5h14v16H5z',refresh:'M20 7v5h-5M4 17v-5h5M6 7a7 7 0 0 1 12-1l2 3M18 17A7 7 0 0 1 6 18l-2-3'};
function Icon({name}){return <svg className="dashboard-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d={paths[name]||paths.pulse}/></svg>}
function Badge({text,tone}){return <span className="react-badge" data-tone={tone||'neutral'}>{text||'Sem conclusão'}</span>}
function Metric({label,value,detail,icon,alert}){return <section className="metric"><div className="metric-head">{label}<Icon name={icon}/></div><strong className={`metric-number ${alert?'alert':''}`}>{value??'—'}</strong><span className="metric-foot">{detail}</span></section>}
function seconds(value){return value==null?'Sem registro':`${value} s`}
function Dashboard({initial}){
 const [data,setData]=useState(initial),[busy,setBusy]=useState(false),[error,setError]=useState(''),[accessLost,setAccessLost]=useState(false),[keyword,setKeyword]=useState(''),[filter,setFilter]=useState('all');
 const busyRef=useRef(false),abortRef=useRef(null),alive=useRef(true);
 const refresh=useCallback(async()=>{
  if(busyRef.current||document.hidden)return;
  busyRef.current=true;setBusy(true);const abort=new AbortController();abortRef.current=abort;let timedOut=false;const timeout=setTimeout(()=>{timedOut=true;abort.abort()},12000);
  try{
   const response=await fetch(initial.links.refresh,{credentials:'same-origin',headers:{Accept:'application/json'},cache:'no-store',signal:abort.signal});
   if([401,403,419].includes(response.status)||response.headers.get('X-AC-Tenant')!==String(initial.tenant_id??'platform')){setAccessLost(true);return;}
   if(!response.ok)throw Error('refresh_failed');
   const next=await response.json();if(next.schema_version!==1||String(next.tenant_id??'platform')!==String(initial.tenant_id??'platform')){setAccessLost(true);return;}
   if(alive.current){setData(next);setError('');}
  }catch(e){if((e.name!=='AbortError'||timedOut)&&alive.current)setError('Não foi possível atualizar. Os últimos dados confirmados continuam abaixo.');}
  finally{clearTimeout(timeout);busyRef.current=false;if(alive.current)setBusy(false);}
 },[initial]);
 useEffect(()=>{
  alive.current=true;if(!initial.tenant||accessLost)return;
  const interval=setInterval(()=>refresh(),10000);const visible=()=>{if(!document.hidden)refresh()};document.addEventListener('visibilitychange',visible);
  return()=>{alive.current=false;clearInterval(interval);abortRef.current?.abort();document.removeEventListener('visibilitychange',visible)};
 },[initial,refresh,accessLost]);
 if(accessLost)return <section className="card empty" role="alert"><h2>Seu contexto de acesso mudou</h2><p>Recarregue o painel para confirmar sua sessão e o tenant atual.</p><a className="button" href="/dashboard">Recarregar painel</a></section>;
 if(!data.tenant)return <section className="card empty"><Icon name="agents"/><h2>Selecione um tenant para começar</h2><p>As métricas operacionais são isoladas por tenant. Escolha o contexto que deseja acompanhar.</p><a className="button" href={data.links.tenants}>Selecionar tenant</a></section>;
 const h=data.health;
 const recent=data.recent.filter(row=>{
  const search=[row.target,row.profile,row.agent].join(' ').toLocaleLowerCase('pt-BR').includes(keyword.toLocaleLowerCase('pt-BR'));
  return search&&(filter==='all'||filter==='active'&&['PENDING','RUNNING'].includes(row.status)||filter==='done'&&row.status==='COMPLETED'||filter==='failed'&&['FAILED','CANCELLED'].includes(row.status));
 });
 return <div className="react-dashboard" data-react-dashboard="ready">
  <div className="dashboard-live"><div role="status" aria-live="polite"><span className={`status-dot ${error?'stale':''}`} aria-hidden="true"/>{error?'Atualização pendente':busy?'Atualizando…':`Dados confirmados às ${data.updated_label}`}</div><button type="button" className="button-secondary small-button" onClick={refresh} disabled={busy}><Icon name="refresh"/>{busy?'Atualizando…':'Atualizar'}</button></div>
  {error&&<p className="feedback" role="status">{error}</p>}
  <section className="operational" data-state={h.state} aria-label="Estado operacional"><div className="operational-intro"><h2>Estado operacional</h2><Badge text={h.state} tone={h.state==='VERDE'?'good':h.state==='NO-GO'?'bad':'warn'}/><p>Operations Health · contexto atual</p></div><div className="operational-grid"><div><small>Worker</small><strong>{h.worker?'HEALTHY':'NO-GO'}</strong></div><div><small>PostgreSQL</small><strong>{h.database?'HEALTHY':'NO-GO'}</strong></div><div><small>Agents</small><strong>{h.agents_online??'—'}/{h.agents_total??'—'} ONLINE</strong></div><div><small>Heartbeat mais antigo</small><strong>{h.missing_heartbeat?`Sem registro em ${h.missing_heartbeat} Agent(s)`:seconds(h.heartbeat_age)}</strong></div><div><small>Poll mais antigo</small><strong>{h.missing_poll?`Sem registro em ${h.missing_poll} Agent(s)`:seconds(h.poll_age)}</strong></div></div></section>
  {h.agents_total===0&&<section className="feedback" role="status"><strong>Nenhum Agent cadastrado neste tenant.</strong><p>Cadastre e aprove um Agent em host autorizado para executar diagnósticos.</p></section>}
  <div className="metrics"><Metric label="Agents online" value={<>{h.agents_online??'—'}<small> / {h.agents_total??'—'}</small></>} detail="Heartbeat, poll e freeze verificados" icon="agents"/><Metric label="Diagnósticos hoje" value={h.today} detail="Criados hoje neste tenant" icon="search"/><Metric label="Indícios de falha" value={h.indications} detail="Diagnósticos de hoje com evidência negativa" icon="pulse" alert={h.indications>0}/><Metric label="Tarefas em andamento" value={h.active} detail="Reservadas ou em execução" icon="activity"/></div>
  <section className="card table-card"><div className="section-head"><div><h2>Diagnósticos recentes</h2><span className="dashboard-count">{recent.length} de {data.recent.length} registros recentes</span></div><a href={data.links.history}>Ver histórico →</a></div>
   <div className="dashboard-filters"><label className="dashboard-search"><Icon name="search"/><span className="sr-only">Buscar nos diagnósticos recentes</span><input type="search" value={keyword} onChange={e=>setKeyword(e.target.value)} placeholder="Buscar alvo, perfil ou Agent"/></label><div className="dashboard-pills" role="group" aria-label="Filtrar diagnósticos">{[['all','Todos'],['active','Em andamento'],['done','Concluídos'],['failed','Interrompidos / falhas']].map(([value,label])=><button type="button" key={value} aria-pressed={filter===value} onClick={()=>setFilter(value)}>{label}</button>)}</div></div>
   <div className="table table-responsive"><table><thead><tr>{['Diagnóstico / alvo','Agent','Status','Classificação','Duração','Horário'].map(label=><th key={label}>{label}</th>)}</tr></thead><tbody>{recent.map(row=><tr key={row.id}><td data-label="Perfil / alvo" className="cell-target"><div><a href={row.url}>{row.profile}</a><small>{row.target}</small></div></td><td data-label="Agent">{row.agent}</td><td data-label="Status"><Badge text={row.status_label} tone={row.status_tone}/></td><td data-label="Classificação"><Badge text={row.classification_label} tone={row.classification_tone}/></td><td data-label="Duração" className="mono">{row.duration}</td><td data-label="Horário"><time dateTime={row.created_at}>{row.created_label}</time></td></tr>)}</tbody></table></div>
   {!recent.length&&<div className="empty"><Icon name="search"/><h3>{data.recent.length?'Nenhum diagnóstico corresponde ao filtro':'Nenhum diagnóstico encontrado'}</h3><p>{data.recent.length?'Ajuste a busca ou consulte o histórico completo.':'Execute um diagnóstico autorizado para acompanhar as evidências.'}</p>{data.recent.length>0?<button type="button" className="button-secondary" onClick={()=>{setKeyword('');setFilter('all')}}>Limpar filtros</button>:data.links.create&&<a className="button button-secondary" href={data.links.create}>Novo diagnóstico</a>}</div>}
  </section>
  <div className="split-grid"><section className="card"><div className="section-head"><h2>Atividade recente</h2>{data.links.audit&&<a href={data.links.audit}>Abrir auditoria →</a>}</div><ul className="mini-list">{data.events.length?data.events.map(event=><li key={event.id}><Icon name="activity"/><div><strong>{event.type}</strong><p>{event.time} · {event.actor}</p></div></li>):<li><div><strong>Sem eventos neste contexto</strong><p>A atividade aparecerá à medida que as ações forem realizadas.</p></div></li>}</ul></section><section className="card"><h2>Seu contexto</h2><dl className="key-values">{[['Tenant',data.tenant.name],['Usuários ativos',data.active_users],['Usuário',data.user.name],['Papel',data.user.role]].map(([label,value])=><div key={label}><dt>{label}</dt><dd>{value??'—'}</dd></div>)}</dl></section></div>
 </div>;
}
class DashboardBoundary extends React.Component{
 constructor(props){super(props);this.state={failed:false}}
 static getDerivedStateFromError(){return {failed:true}}
 render(){return this.state.failed?<section className="card empty" role="alert"><h2>A visualização não pôde ser concluída</h2><p>Recarregue para consultar novamente os dados confirmados pelo servidor.</p><a className="button" href="/dashboard">Recarregar painel</a></section>:this.props.children}
}
const mount=document.getElementById('dashboard-react');
if(mount){try{const initial=JSON.parse(mount.dataset.dashboard);if(initial.schema_version!==1)throw Error('invalid_dashboard');mount.removeAttribute('data-dashboard');createRoot(mount).render(<DashboardBoundary><Dashboard initial={initial}/></DashboardBoundary>);}catch{const notice=document.createElement('p');notice.className='feedback';notice.textContent='A visualização dinâmica não carregou. Os dados confirmados pelo servidor estão abaixo.';mount.prepend(notice);}}
