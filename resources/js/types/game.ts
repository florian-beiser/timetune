export type GameStateName =
    | 'LOBBY'
    | 'ROUND_OPEN'
    | 'ROUND_CLOSED'
    | 'REVEALED'
    | 'TIEBREAKER'
    | 'FINISHED';

export type SongImage = { sm: string; lg: string };

export type Card = {
    id: number;
    title: string;
    artist: string;
    year: number;
    image: SongImage | null;
};

export type Theme = { accent: string; background: string };

export type Branding = {
    logo_url: string | null;
    theme: Theme | null;
};

export type PublicState = {
    state: GameStateName;
    round_number: number;
    total_rounds: number;
    round_id: number | null;
    round_skipped: boolean;
    closes_at: string | null;
    bonus_options: string[] | null;
    tiebreaker: { title: string; artist: string } | null;
};

export type GameInfo = {
    id: number;
    code: string;
    name: string;
};

export type RoundResult = {
    round_number: number;
    song: Card;
    submitted: boolean;
    participated: boolean;
    position: number | null;
    is_correct: boolean;
    correct_position: number;
    timeline: Card[];
    score: number;
    rank: number;
    total_players: number;
    bonus_correct: boolean | null;
    bonus_points: number;
};

export type Me = {
    player: { id: number; name: string };
    game: GameInfo;
    state: PublicState;
    start_card: Card;
    timeline: Card[];
    placement: number | null;
    bonus_choice: number | null;
    score: number;
    bonus_points: number;
    rank: number;
    total_players: number;
    joined_round: number | null;
    result: RoundResult | null;
    tiebreak_year: number | null;
    tiebreak_deviation: number | null;
};

export type LeaderboardRow = {
    player_id: number;
    name: string;
    score: number;
    bonus: number;
    rank: number;
    tiebreak_deviation: number | null;
};

export type RevealSummary = {
    song: {
        title: string;
        artist: string;
        year: number;
        image: SongImage | null;
    };
    percent_correct: number;
};

export type ScreenProps = {
    game: GameInfo;
    branding: Branding;
    state: PublicState;
    join_url: string;
    join_url_short: string;
    qr_svg: string | null;
    total_players: number;
    submitted: number;
    reveal: RevealSummary | null;
    top10: LeaderboardRow[];
    podium: LeaderboardRow[];
};

export type SongRow = Card & {
    role: 'ROUND' | 'START_POOL' | 'TIEBREAKER';
    status?: 'running' | 'revealed' | 'skipped' | null;
    percent_correct?: number | null;
};

export type HostProps = ScreenProps & {
    host_url: string;
    screen_url: string;
    options: {
        bonus_question: boolean;
        countdown_seconds: number | null;
        join_url: string | null;
    };
    default_join_url: string;
    next_state: GameStateName | null;
    has_next_round: boolean;
    has_tiebreaker_song: boolean;
    tie_in_top_three: boolean;
    current_song: SongRow | null;
    round_opened_at: string | null;
    songs: { round: SongRow[]; start_pool: SongRow[]; tiebreaker: SongRow[] };
    players: HostPlayer[];
    rounds_played: number;
    inactive_after_seconds: number;
};

export type HostPlayer = {
    id: number;
    name: string;
    is_hidden: boolean;
    score: number;
    submissions: number;
    joined_at: string;
    last_seen_at: string | null;
};
