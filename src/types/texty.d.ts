export interface TextyGlobal {
    version?: {
        lite?: string;
        business?: string;
    };
    docs_url?: string;
}

declare global {
    interface Window {
        texty?: TextyGlobal;
    }
}
