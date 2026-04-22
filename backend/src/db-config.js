export const dbConfig = {
  name: process.env.DB_NAME || "spesecondominiali",
  host: process.env.DB_HOST || "ar18382-001.eu.clouddb.ovh.net",
  port: Number(process.env.DB_PORT || 35918),
  user: process.env.DB_USER || "spesemio",
  password: process.env.DB_PASSWORD || "SGp56tYd1W6nSGp56tYd1W6n"
};

export function validateDbConfig() {
  if (!dbConfig.password) {
    return {
      valid: false,
      message: "Imposta DB_PASSWORD nelle variabili ambiente prima della pubblicazione."
    };
  }
  return { valid: true, message: "Configurazione DB valida." };
}
