export type CabinetCurrency = {
    code: string;
    symbol: string;
    minor_unit: number;
};

export type Cabinet = {
    name: string;
    phone: string | null;
    email: string | null;
    address: string | null;
    city: string | null;
    logo_path: string | null;
    logo_url: string | null;
    timezone: string;
    currency: CabinetCurrency;
};
