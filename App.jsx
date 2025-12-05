import { useEffect, useMemo, useState } from "react";
import { fetchTodayAttendance } from "./attendanceService";

// Clases de gradiente usando colores del tema (tailwind.config.js)
const ACTION_LABELS = {
  check_in: "Entrar",
  break_out: "Salir a descanso",
  break_in: "Volver de descanso",
  check_out: "Salir",
};

const ACTION_GRADIENTS = {
  check_in: "from-brand-accent via-brand-primary to-brand-primary-dark",
  break_out: "from-brand-primary via-brand-primary-dark to-brand-dark",
  break_in: "from-brand-primary-dark via-brand-primary to-brand-accent",
  check_out: "from-brand-alert via-brand-primary to-brand-primary-dark",
};

const STATUS_LABELS = {
  idle: "Fuera de turno",
  working: "En turno",
  break: "En descanso",
  completed: "Turno cerrado",
};

const pad = (value) => value.toString().padStart(2, "0");

const formatDuration = (totalMinutes) => {
  const hours = Math.floor(totalMinutes / 60);
  const minutes = totalMinutes % 60;
  return `${pad(hours)}h ${pad(minutes)}m`;
};

const localDateTimeString = (date = new Date()) => {
  const year = date.getFullYear();
  const month = pad(date.getMonth() + 1);
  const day = pad(date.getDate());
  const hours = pad(date.getHours());
  const minutes = pad(date.getMinutes());
  const seconds = pad(date.getSeconds());
  return `${year}-${month}-${day}T${hours}:${minutes}:${seconds}`;
};

const toTime = (value) =>
  new Date(value).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });

const getNextAction = (logs) => {
  if (!logs.length) return "check_in";
  const last = logs[logs.length - 1].type;
  if (last === "check_out") return "check_in";
  const order = ["check_in", "break_out", "break_in", "check_out"];
  const nextIndex = order.indexOf(last) + 1;
  return order[nextIndex] || "check_out";
};

const getMode = (logs) => {
  if (!logs.length) return "idle";
  const last = logs[logs.length - 1].type;
  if (last === "check_out") return "completed";
  if (last === "break_out") return "break";
  if (last === "break_in" || last === "check_in") return "working";
  return "idle";
};

const computeDurations = (logs, now) => {
  const sorted = [...logs].sort(
    (a, b) => new Date(a.timestamp).getTime() - new Date(b.timestamp).getTime()
  );
  let mode = "idle";
  let lastTs = null;
  let workingMs = 0;
  let breakMs = 0;

  sorted.forEach((log) => {
    const ts = new Date(log.timestamp);
    if (lastTs) {
      if (mode === "working") workingMs += ts.getTime() - lastTs.getTime();
      if (mode === "break") breakMs += ts.getTime() - lastTs.getTime();
    }

    if (log.type === "check_in" || log.type === "break_in") mode = "working";
    if (log.type === "break_out") mode = "break";
    if (log.type === "check_out") mode = "idle";

    lastTs = ts;
  });

  if (lastTs) {
    if (mode === "working") workingMs += now.getTime() - lastTs.getTime();
    if (mode === "break") breakMs += now.getTime() - lastTs.getTime();
  }

  return {
    workingMinutes: Math.max(0, Math.round(workingMs / 60000)),
    breakMinutes: Math.max(0, Math.round(breakMs / 60000)),
  };
};

const computeLateStatus = (logs, shift, day) => {
  const checkIn = logs.find((log) => log.type === "check_in");
  if (!checkIn) return { isLate: false, minutesLate: 0, checkInTime: null };

  const start = new Date(`${day}T${shift.start_time}:00`);
  const allowed = new Date(
    start.getTime() + shift.tolerance_minutes * 60 * 1000
  );
  const checkInTs = new Date(checkIn.timestamp);
  const diffMinutes = Math.round((checkInTs.getTime() - allowed.getTime()) / 60000);

  return {
    isLate: diffMinutes > 0,
    minutesLate: Math.max(0, diffMinutes),
    checkInTime: checkInTs,
  };
};

export default function App() {
  const [data, setData] = useState(null);
  const [logs, setLogs] = useState([]);
  const [now, setNow] = useState(new Date());

  useEffect(() => {
    fetchTodayAttendance().then((payload) => {
      setData(payload);
      setLogs(payload.attendanceLogs || []);
    });
  }, []);

  useEffect(() => {
    const id = setInterval(() => setNow(new Date()), 1000);
    return () => clearInterval(id);
  }, []);

  if (!data) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-50 text-brand-dark">
        <p className="rounded-full bg-white/80 px-4 py-2 text-sm shadow">Cargando turno...</p>
      </div>
    );
  }

  const nextAction = useMemo(() => getNextAction(logs), [logs]);
  const mode = useMemo(() => getMode(logs), [logs]);
  const { workingMinutes, breakMinutes } = useMemo(
    () => computeDurations(logs, now),
    [logs, now]
  );

  const shiftMinutes = useMemo(() => {
    const start = new Date(`${data.today}T${data.user.shift.start_time}:00`);
    const end = new Date(`${data.today}T${data.user.shift.end_time}:00`);
    return Math.max(0, Math.round((end.getTime() - start.getTime()) / 60000));
  }, [data]);

  const lateStatus = useMemo(
    () => computeLateStatus(logs, data.user.shift, data.today),
    [logs, data]
  );

  const completion = shiftMinutes
    ? Math.min(100, Math.round((workingMinutes / shiftMinutes) * 100))
    : 0;
  const readyForCheckout = mode === "working" && nextAction === "check_out";

  const handleAction = () => {
    const ts = localDateTimeString();
    setLogs((prev) => [...prev, { type: nextAction, timestamp: ts }]);
  };

  const brandBg = `bg-gradient-to-br ${ACTION_GRADIENTS[nextAction]}`;
  const statusTone = lateStatus.isLate
    ? "text-brand-alert bg-brand-alert/10"
    : "text-brand-accent bg-brand-accent/10";

  return (
    <div className="min-h-screen bg-slate-50 text-brand-dark">
      <div className="absolute inset-0 bg-gradient-to-b from-slate-50 via-white to-slate-100" />
      <div className="relative mx-auto max-w-6xl px-6 py-10">
        <header className="mb-10 flex items-center justify-between">
          <div>
            <p className="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">
              CloudTimes Evolution
            </p>
            <h1 className="text-3xl font-bold text-brand-dark">
              Panel de fichaje premium
            </h1>
            <p className="text-sm text-slate-500">
              Identidad Movistar inspirada en Awwwards + micro-interacciones
            </p>
          </div>
          <div className="flex items-center gap-3 rounded-full bg-white/80 px-4 py-2 shadow-lg backdrop-blur-md">
            <div className="h-10 w-10 rounded-full bg-gradient-to-br from-brand-primary to-brand-primary-dark p-[2px] shadow-inner">
              <div className="flex h-full w-full items-center justify-center rounded-full bg-white text-sm font-semibold text-brand-dark">
                {data.user.name[0]}
              </div>
            </div>
            <div>
              <p className="text-sm font-semibold leading-tight">{data.user.name}</p>
              <p className="text-xs text-slate-500">Rol: {data.user.role}</p>
            </div>
          </div>
        </header>

        <div className="grid grid-cols-1 gap-8 lg:grid-cols-2">
          <section className="relative overflow-hidden rounded-2xl border border-white/60 bg-white/70 shadow-xl backdrop-blur-md">
            <div className="absolute -left-10 -top-10 h-52 w-52 rounded-full bg-brand-primary/10 blur-3xl" />
            <div className="absolute -right-6 -bottom-6 h-40 w-40 rounded-full bg-brand-accent/20 blur-3xl" />
            <div className="relative flex flex-col items-center px-8 py-10">
              <div className="mb-6 text-sm font-medium uppercase tracking-[0.25em] text-slate-500">
                Hero Clock
              </div>
              <div
                className={`group ${brandBg} mb-6 rounded-full p-[3px] shadow-[0_20px_60px_rgba(0,0,0,0.12)] transition-all duration-300 hover:-translate-y-1`}
              >
                <button
                  onClick={handleAction}
                  className="flex h-40 w-40 flex-col items-center justify-center rounded-full bg-white/80 text-center text-lg font-semibold text-brand-dark backdrop-blur-md transition-all duration-300 group-hover:scale-105"
                >
                  <span className="text-sm font-medium text-slate-500">Proximo paso</span>
                  <span className="text-xl text-brand-dark">{ACTION_LABELS[nextAction]}</span>
                </button>
              </div>
              <div className="flex flex-col items-center gap-2">
                <div className="text-5xl font-bold text-brand-dark tabular-nums">
                  {toTime(now)}
                </div>
                <p className={`rounded-full px-4 py-2 text-sm font-semibold ${statusTone}`}>
                  {lateStatus.isLate
                    ? `Retraso: ${lateStatus.minutesLate} min`
                    : "Dentro de la tolerancia"}
                </p>
              </div>
              <div className="mt-6 grid w-full grid-cols-1 gap-4 md:grid-cols-3">
                <div className="rounded-2xl border border-white/60 bg-white/80 p-4 shadow-sm transition-all duration-300 hover:-translate-y-1">
                  <p className="text-xs uppercase tracking-[0.15em] text-slate-500">Turno</p>
                  <p className="text-lg font-semibold">{data.user.shift.name}</p>
                  <p className="text-sm text-slate-500">
                    {data.user.shift.start_time} - {data.user.shift.end_time}
                  </p>
                </div>
                <div className="rounded-2xl border border-white/60 bg-white/80 p-4 shadow-sm transition-all duration-300 hover:-translate-y-1">
                  <p className="text-xs uppercase tracking-[0.15em] text-slate-500">Estado</p>
                  <p className="text-lg font-semibold">{STATUS_LABELS[mode]}</p>
                  <p className="text-sm text-slate-500">Proximo: {ACTION_LABELS[nextAction]}</p>
                </div>
                <div className="rounded-2xl border border-white/60 bg-white/80 p-4 shadow-sm transition-all duration-300 hover:-translate-y-1">
                  <p className="text-xs uppercase tracking-[0.15em] text-slate-500">
                    Horas objetivo
                  </p>
                  <p className="text-lg font-semibold">{formatDuration(shiftMinutes)}</p>
                  <p className="text-sm text-slate-500">Por turno</p>
                </div>
              </div>
            </div>
          </section>

          <section className="flex flex-col gap-6">
            <div className="rounded-2xl border border-white/70 bg-white/80 p-6 shadow-xl backdrop-blur-md">
              <div className="flex items-center justify-between">
                <h2 className="text-xl font-semibold text-brand-dark">Dashboard del dia</h2>
                <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                  {data.today}
                </span>
              </div>
              <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                <div className="rounded-xl bg-gradient-to-br from-white to-slate-50/80 p-4 shadow-sm">
                  <p className="text-xs uppercase tracking-[0.15em] text-slate-500">
                    Horas trabajadas
                  </p>
                  <p className="text-2xl font-bold text-brand-dark">
                    {formatDuration(workingMinutes)}
                  </p>
                  <p className="text-xs text-slate-500">Incluye tiempo actual</p>
                </div>
                <div className="rounded-xl bg-gradient-to-br from-white to-slate-50/80 p-4 shadow-sm">
                  <p className="text-xs uppercase tracking-[0.15em] text-slate-500">
                    Descanso
                  </p>
                  <p className="text-2xl font-bold text-brand-dark">
                    {formatDuration(breakMinutes)}
                  </p>
                  <p className="text-xs text-slate-500">Tiempo pausado</p>
                </div>
                <div className="rounded-xl bg-gradient-to-br from-white to-slate-50/80 p-4 shadow-sm">
                  <p className="text-xs uppercase tracking-[0.15em] text-slate-500">
                    Progreso turno
                  </p>
                  <p className="text-2xl font-bold text-brand-dark">{completion}%</p>
                  <div className="mt-2 h-2 w-full rounded-full bg-slate-100">
                    <div
                      className="h-2 rounded-full bg-gradient-to-r from-brand-primary to-brand-primary-dark transition-all"
                      style={{ width: `${completion}%` }}
                    />
                  </div>
                </div>
              </div>
              {lateStatus.checkInTime && (
                <div className="mt-4 flex items-center gap-3 rounded-xl bg-white/70 p-4 shadow-inner">
                  <div
                    className={`h-10 w-10 rounded-full ${brandBg} flex items-center justify-center text-white text-sm font-semibold`}
                  >
                    In
                  </div>
                  <div>
                    <p className="text-sm text-slate-500">Hora de entrada real</p>
                    <p className="text-lg font-semibold text-brand-dark">
                      {toTime(lateStatus.checkInTime)}
                    </p>
                  </div>
                  <div className="ml-auto">
                    <span className={`rounded-full px-3 py-1 text-xs font-semibold ${statusTone}`}>
                      {lateStatus.isLate
                        ? `Tarde +${lateStatus.minutesLate}m`
                        : "En tiempo"}
                    </span>
                  </div>
                </div>
              )}
            </div>

            <div className="rounded-2xl border border-white/70 bg-white/80 p-6 shadow-xl backdrop-blur-md">
              <div className="mb-4 flex items-center justify-between">
                <h3 className="text-lg font-semibold text-brand-dark">Timeline</h3>
                <span className="text-xs uppercase tracking-[0.2em] text-slate-500">
                  Detalle del fichaje
                </span>
              </div>
              <div className="space-y-3">
                {[...logs]
                  .sort(
                    (a, b) =>
                      new Date(b.timestamp).getTime() - new Date(a.timestamp).getTime()
                  )
                  .map((log, index) => (
                    <div
                      key={`${log.timestamp}-${index}`}
                      className="flex items-center gap-3 rounded-xl border border-white/60 bg-white/80 p-4 shadow-sm transition-all duration-200 hover:-translate-y-[2px]"
                    >
                      <div
                        className={`flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br ${ACTION_GRADIENTS[log.type]} text-white text-sm font-semibold shadow`}
                      >
                        {ACTION_LABELS[log.type].slice(0, 2)}
                      </div>
                      <div className="flex-1">
                        <p className="text-sm font-semibold text-brand-dark">
                          {ACTION_LABELS[log.type]}
                        </p>
                        <p className="text-xs text-slate-500">
                          {new Date(log.timestamp).toLocaleString()}
                        </p>
                      </div>
                      <div className="text-xs font-semibold uppercase tracking-[0.1em] text-slate-600">
                        {STATUS_LABELS[getMode([log])]}
                      </div>
                    </div>
                  ))}
              </div>
              <div className="mt-6 rounded-xl border border-dashed border-slate-200 bg-slate-50/80 px-4 py-3 text-sm text-slate-600">
                Tip: el botón central recorre el flujo completo. Este mock usa promesas para
                que puedas cambiar a fetch() contra PHP sin tocar la UI.
              </div>
            </div>
          </section>
        </div>

        {readyForCheckout && (
          <div className="mt-8 flex items-center justify-between rounded-2xl border border-white/70 bg-white/80 p-5 shadow-lg backdrop-blur-md">
            <div>
              <p className="text-sm font-semibold text-brand-dark">Turno casi terminado</p>
              <p className="text-sm text-slate-500">
                Puedes cerrar con "Salir" o extender si se requiere tiempo extra.
              </p>
            </div>
            <button
              onClick={handleAction}
              className="rounded-full bg-gradient-to-r from-brand-alert via-brand-primary to-brand-primary-dark px-5 py-2 text-sm font-semibold text-white shadow-lg transition-all duration-200 hover:scale-105"
            >
              Cerrar turno
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
