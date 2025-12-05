// Servicio de datos mockeado para simular la API PHP/MySQL.
// Reemplaza el contenido de fetchTodayAttendance con llamadas fetch() cuando la API esté lista.

const todayISO = new Date().toISOString().slice(0, 10);

const MOCK_DATA = {
  today: todayISO,
  user: {
    id: 2,
    name: "Bruno Perez",
    role: "user",
    shift: {
      id: 1,
      name: "Turno Manana",
      start_time: "09:00",
      end_time: "17:00",
      tolerance_minutes: 15,
    },
  },
  attendanceLogs: [
    { type: "check_in", timestamp: `${todayISO}T09:12:00` },
    { type: "break_out", timestamp: `${todayISO}T13:05:00` },
    { type: "break_in", timestamp: `${todayISO}T13:37:00` },
  ],
};

export function fetchTodayAttendance() {
  return new Promise((resolve) => {
    setTimeout(() => resolve(MOCK_DATA), 150);
  });
}
