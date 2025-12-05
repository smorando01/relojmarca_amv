<!doctype html>
<html lang="es">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Control de Asistencia</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        theme: {
          extend: {
            colors: {
              "brand-primary": "#00BFE0",
              "brand-primary-dark": "#0090C0",
              "brand-accent": "#80D040",
              "brand-alert": "#FF6B6B",
              "brand-dark": "#1A2B3C",
            },
          },
        },
      };
    </script>
  </head>
  <body class="min-h-screen bg-gradient-to-br from-white via-slate-50 to-slate-100">
    <div id="root"></div>

    <script src="https://unpkg.com/react@18/umd/react.production.min.js" crossorigin></script>
    <script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js" crossorigin></script>
    <script src="https://unpkg.com/babel-standalone@6/babel.min.js"></script>

    <script type="text/babel">
      const { useEffect, useMemo, useState } = React;

      const EMPLOYEES = [
        { id: 1, nombre: "Ana Torres" },
        { id: 2, nombre: "Luis Perez" },
      ];

      const ACTIONS = [
        { key: "Entrada", label: "Entrada", tone: "from-brand-primary to-brand-primary-dark" },
        { key: "Salida Descanso", label: "Salida Descanso", tone: "from-brand-primary to-brand-accent" },
        { key: "Vuelta Descanso", label: "Vuelta Descanso", tone: "from-brand-accent to-brand-primary" },
        { key: "Salida", label: "Salida", tone: "from-brand-primary-dark to-brand-dark" },
      ];

      const formatDateTime = (value) =>
        new Date(value).toLocaleString([], {
          year: "numeric",
          month: "2-digit",
          day: "2-digit",
          hour: "2-digit",
          minute: "2-digit",
        });

      function App() {
        const [selectedEmployeeId, setSelectedEmployeeId] = useState(EMPLOYEES[0].id);
        const [records, setRecords] = useState([]);
        const [loading, setLoading] = useState(false);
        const [saving, setSaving] = useState(false);
        const [message, setMessage] = useState(null);

        const selectedEmployee = useMemo(
          () => EMPLOYEES.find((emp) => emp.id === Number(selectedEmployeeId)),
          [selectedEmployeeId]
        );

        useEffect(() => {
          loadRecords(selectedEmployeeId);
        }, [selectedEmployeeId]);

        async function loadRecords(employeeId) {
          setLoading(true);
          setMessage(null);
          try {
            const response = await fetch(`api.php?empleado_id=${employeeId}`);
            const data = await response.json();
            if (!data.success) {
              throw new Error(data.error || "No se pudo cargar el historial.");
            }
            setRecords(data.data || []);
          } catch (error) {
            setMessage(error.message);
            setRecords([]);
          } finally {
            setLoading(false);
          }
        }

        async function sendPunch(tipo) {
          if (!selectedEmployeeId) return;
          setSaving(true);
          setMessage(null);
          try {
            const response = await fetch("api.php", {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({ empleado_id: Number(selectedEmployeeId), tipo }),
            });
            const data = await response.json();
            if (!data.success) {
              throw new Error(data.error || "No se pudo guardar el fichaje.");
            }
            setMessage("Fichaje guardado correctamente.");
            await loadRecords(selectedEmployeeId);
          } catch (error) {
            setMessage(error.message);
          } finally {
            setSaving(false);
          }
        }

        return (
          <div className="min-h-screen text-brand-dark">
            <div className="mx-auto max-w-5xl px-4 py-10">
              <header className="mb-8 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                  <p className="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">
                    Control de Asistencia
                  </p>
                  <h1 className="text-3xl font-bold text-brand-dark">Panel de fichaje simple</h1>
                  <p className="text-sm text-slate-600">
                    React + Tailwind al frente, PHP + MySQL en el servidor.
                  </p>
                </div>
                <div className="flex items-center gap-3 rounded-2xl bg-white/90 px-4 py-3 shadow">
                  <div className="flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-brand-primary to-brand-accent text-white font-semibold">
                    {selectedEmployee?.nombre?.[0] || "?"}
                  </div>
                  <div>
                    <p className="text-sm font-semibold">Empleado</p>
                    <select
                      className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-brand-primary focus:outline-none"
                      value={selectedEmployeeId}
                      onChange={(e) => setSelectedEmployeeId(Number(e.target.value))}
                    >
                      {EMPLOYEES.map((emp) => (
                        <option key={emp.id} value={emp.id}>
                          {emp.nombre}
                        </option>
                      ))}
                    </select>
                  </div>
                </div>
              </header>

              <main className="space-y-8">
                <section className="rounded-2xl border border-white/80 bg-white/90 p-6 shadow-xl backdrop-blur">
                  <div className="mb-5 flex items-center justify-between">
                    <div>
                      <h2 className="text-xl font-semibold">Fichar ahora</h2>
                      <p className="text-sm text-slate-600">Envía los fichajes directamente a api.php.</p>
                    </div>
                    {message && (
                      <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
                        {message}
                      </span>
                    )}
                  </div>
                  <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {ACTIONS.map((action) => (
                      <button
                        key={action.key}
                        disabled={saving}
                        onClick={() => sendPunch(action.key)}
                        className={`group rounded-2xl bg-gradient-to-br ${action.tone} p-[2px] transition hover:-translate-y-1 hover:shadow-lg disabled:cursor-not-allowed disabled:opacity-60`}
                      >
                        <div className="flex h-28 flex-col items-start justify-between rounded-2xl bg-white/95 px-4 py-3 text-left">
                          <p className="text-xs uppercase tracking-[0.15em] text-slate-500">Accion</p>
                          <p className="text-lg font-semibold text-brand-dark">{action.label}</p>
                          <span className="text-xs font-semibold text-brand-primary">
                            {saving ? "Enviando..." : "Registrar"}
                          </span>
                        </div>
                      </button>
                    ))}
                  </div>
                </section>

                <section className="rounded-2xl border border-white/80 bg-white/95 p-6 shadow-xl backdrop-blur">
                  <div className="mb-4 flex items-center justify-between">
                    <div>
                      <h3 className="text-lg font-semibold">Historial de fichajes</h3>
                      <p className="text-sm text-slate-600">Lectura en vivo desde MySQL via GET a api.php.</p>
                    </div>
                    {loading && (
                      <span className="text-xs font-semibold uppercase tracking-[0.15em] text-brand-primary">
                        Cargando...
                      </span>
                    )}
                  </div>
                  <div className="overflow-hidden rounded-xl border border-slate-200">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                      <thead className="bg-slate-50">
                        <tr>
                          <th className="px-4 py-2 text-left font-semibold text-slate-600">Empleado</th>
                          <th className="px-4 py-2 text-left font-semibold text-slate-600">Tipo</th>
                          <th className="px-4 py-2 text-left font-semibold text-slate-600">Fecha y hora</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100 bg-white">
                        {records.length === 0 && !loading && (
                          <tr>
                            <td className="px-4 py-6 text-center text-slate-500" colSpan={3}>
                              Sin fichajes registrados para este usuario.
                            </td>
                          </tr>
                        )}
                        {records.map((row) => (
                          <tr key={row.id}>
                            <td className="px-4 py-3">{row.empleado || selectedEmployee?.nombre}</td>
                            <td className="px-4 py-3 font-semibold text-brand-primary">{row.tipo}</td>
                            <td className="px-4 py-3 text-slate-600">{formatDateTime(row.fecha_hora)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </section>
              </main>
            </div>
          </div>
        );
      }

      const root = ReactDOM.createRoot(document.getElementById("root"));
      root.render(<App />);
    </script>
  </body>
</html>
