--
-- PostgreSQL database dump
--

\restrict eYPfmx07xz1p9E6FE8Q1Ggw4dtvOtyXqfEiOX3hazhPaCMcwLoontioqPg0oyeO

-- Dumped from database version 16.15 (Ubuntu 16.15-0ubuntu0.24.04.1)
-- Dumped by pg_dump version 16.15 (Ubuntu 16.15-0ubuntu0.24.04.1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

ALTER TABLE IF EXISTS ONLY public.tickets DROP CONSTRAINT IF EXISTS tickets_user_id_fkey;
ALTER TABLE IF EXISTS ONLY public.tickets DROP CONSTRAINT IF EXISTS tickets_created_by_fkey;
ALTER TABLE IF EXISTS ONLY public.tickets DROP CONSTRAINT IF EXISTS tickets_assigned_to_fkey;
ALTER TABLE IF EXISTS ONLY public.ticket_history DROP CONSTRAINT IF EXISTS ticket_history_ticket_id_fkey;
ALTER TABLE IF EXISTS ONLY public.ticket_history DROP CONSTRAINT IF EXISTS ticket_history_actor_id_fkey;
ALTER TABLE IF EXISTS ONLY public.ticket_comments DROP CONSTRAINT IF EXISTS ticket_comments_user_id_fkey;
ALTER TABLE IF EXISTS ONLY public.ticket_comments DROP CONSTRAINT IF EXISTS ticket_comments_ticket_id_fkey;
ALTER TABLE IF EXISTS ONLY public.notifications DROP CONSTRAINT IF EXISTS notifications_user_id_fkey;
ALTER TABLE IF EXISTS ONLY public.kb_templates DROP CONSTRAINT IF EXISTS kb_templates_created_by_fkey;
ALTER TABLE IF EXISTS ONLY public.kb_articles DROP CONSTRAINT IF EXISTS kb_articles_created_by_fkey;
ALTER TABLE IF EXISTS ONLY public.change_requests DROP CONSTRAINT IF EXISTS change_requests_user_id_fkey;
ALTER TABLE IF EXISTS ONLY public.change_requests DROP CONSTRAINT IF EXISTS change_requests_created_by_fkey;
ALTER TABLE IF EXISTS ONLY public.change_requests DROP CONSTRAINT IF EXISTS change_requests_assigned_to_fkey;
ALTER TABLE IF EXISTS ONLY public.change_request_items DROP CONSTRAINT IF EXISTS change_request_items_cr_id_fkey;
ALTER TABLE IF EXISTS ONLY public.change_request_history DROP CONSTRAINT IF EXISTS change_request_history_cr_id_fkey;
ALTER TABLE IF EXISTS ONLY public.change_request_history DROP CONSTRAINT IF EXISTS change_request_history_actor_id_fkey;
ALTER TABLE IF EXISTS ONLY public.change_request_comments DROP CONSTRAINT IF EXISTS change_request_comments_user_id_fkey;
ALTER TABLE IF EXISTS ONLY public.change_request_comments DROP CONSTRAINT IF EXISTS change_request_comments_cr_id_fkey;
ALTER TABLE IF EXISTS ONLY public.activity_log DROP CONSTRAINT IF EXISTS activity_log_user_id_fkey;
DROP INDEX IF EXISTS public.idx_users_role;
DROP INDEX IF EXISTS public.idx_users_external;
DROP INDEX IF EXISTS public.idx_users_auth_source;
DROP INDEX IF EXISTS public.idx_users_active;
DROP INDEX IF EXISTS public.idx_tickets_user_id;
DROP INDEX IF EXISTS public.idx_tickets_status;
DROP INDEX IF EXISTS public.idx_tickets_sla_due;
DROP INDEX IF EXISTS public.idx_tickets_resolved_at;
DROP INDEX IF EXISTS public.idx_tickets_priority;
DROP INDEX IF EXISTS public.idx_tickets_division;
DROP INDEX IF EXISTS public.idx_tickets_created_at;
DROP INDEX IF EXISTS public.idx_tickets_category;
DROP INDEX IF EXISTS public.idx_tickets_assigned;
DROP INDEX IF EXISTS public.idx_notif_user;
DROP INDEX IF EXISTS public.idx_login_ip_user_time;
DROP INDEX IF EXISTS public.idx_kb_kategori;
DROP INDEX IF EXISTS public.idx_holidays_tanggal;
DROP INDEX IF EXISTS public.idx_history_ticket;
DROP INDEX IF EXISTS public.idx_history_created;
DROP INDEX IF EXISTS public.idx_cri_jenis;
DROP INDEX IF EXISTS public.idx_cri_cr;
DROP INDEX IF EXISTS public.idx_crh_created;
DROP INDEX IF EXISTS public.idx_crh_cr;
DROP INDEX IF EXISTS public.idx_crc_created;
DROP INDEX IF EXISTS public.idx_crc_cr;
DROP INDEX IF EXISTS public.idx_cr_user;
DROP INDEX IF EXISTS public.idx_cr_status;
DROP INDEX IF EXISTS public.idx_cr_sla_due;
DROP INDEX IF EXISTS public.idx_cr_priority;
DROP INDEX IF EXISTS public.idx_cr_number;
DROP INDEX IF EXISTS public.idx_cr_created;
DROP INDEX IF EXISTS public.idx_cr_assigned;
DROP INDEX IF EXISTS public.idx_cr_aplikasi;
DROP INDEX IF EXISTS public.idx_comments_ticket;
DROP INDEX IF EXISTS public.idx_comments_created;
DROP INDEX IF EXISTS public.idx_activity_user;
DROP INDEX IF EXISTS public.idx_activity_created;
ALTER TABLE IF EXISTS ONLY public.users DROP CONSTRAINT IF EXISTS users_username_key;
ALTER TABLE IF EXISTS ONLY public.users DROP CONSTRAINT IF EXISTS users_pkey;
ALTER TABLE IF EXISTS ONLY public.tickets DROP CONSTRAINT IF EXISTS tickets_ticket_number_key;
ALTER TABLE IF EXISTS ONLY public.tickets DROP CONSTRAINT IF EXISTS tickets_pkey;
ALTER TABLE IF EXISTS ONLY public.ticket_history DROP CONSTRAINT IF EXISTS ticket_history_pkey;
ALTER TABLE IF EXISTS ONLY public.ticket_comments DROP CONSTRAINT IF EXISTS ticket_comments_pkey;
ALTER TABLE IF EXISTS ONLY public.settings DROP CONSTRAINT IF EXISTS settings_pkey;
ALTER TABLE IF EXISTS ONLY public.notifications DROP CONSTRAINT IF EXISTS notifications_pkey;
ALTER TABLE IF EXISTS ONLY public.login_attempts DROP CONSTRAINT IF EXISTS login_attempts_pkey;
ALTER TABLE IF EXISTS ONLY public.kb_templates DROP CONSTRAINT IF EXISTS kb_templates_pkey;
ALTER TABLE IF EXISTS ONLY public.kb_articles DROP CONSTRAINT IF EXISTS kb_articles_pkey;
ALTER TABLE IF EXISTS ONLY public.holidays DROP CONSTRAINT IF EXISTS holidays_tanggal_key;
ALTER TABLE IF EXISTS ONLY public.holidays DROP CONSTRAINT IF EXISTS holidays_pkey;
ALTER TABLE IF EXISTS ONLY public.divisions DROP CONSTRAINT IF EXISTS divisions_pkey;
ALTER TABLE IF EXISTS ONLY public.divisions DROP CONSTRAINT IF EXISTS divisions_name_key;
ALTER TABLE IF EXISTS ONLY public.change_requests DROP CONSTRAINT IF EXISTS change_requests_pkey;
ALTER TABLE IF EXISTS ONLY public.change_requests DROP CONSTRAINT IF EXISTS change_requests_cr_number_key;
ALTER TABLE IF EXISTS ONLY public.change_request_items DROP CONSTRAINT IF EXISTS change_request_items_pkey;
ALTER TABLE IF EXISTS ONLY public.change_request_history DROP CONSTRAINT IF EXISTS change_request_history_pkey;
ALTER TABLE IF EXISTS ONLY public.change_request_comments DROP CONSTRAINT IF EXISTS change_request_comments_pkey;
ALTER TABLE IF EXISTS ONLY public.activity_log DROP CONSTRAINT IF EXISTS activity_log_pkey;
ALTER TABLE IF EXISTS public.users ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.tickets ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.ticket_history ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.ticket_comments ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.notifications ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.login_attempts ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.kb_templates ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.kb_articles ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.holidays ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.divisions ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.change_requests ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.change_request_items ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.change_request_history ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.change_request_comments ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.activity_log ALTER COLUMN id DROP DEFAULT;
DROP SEQUENCE IF EXISTS public.users_id_seq;
DROP TABLE IF EXISTS public.users;
DROP SEQUENCE IF EXISTS public.tickets_id_seq;
DROP TABLE IF EXISTS public.tickets;
DROP SEQUENCE IF EXISTS public.ticket_history_id_seq;
DROP TABLE IF EXISTS public.ticket_history;
DROP SEQUENCE IF EXISTS public.ticket_comments_id_seq;
DROP TABLE IF EXISTS public.ticket_comments;
DROP TABLE IF EXISTS public.settings;
DROP SEQUENCE IF EXISTS public.notifications_id_seq;
DROP TABLE IF EXISTS public.notifications;
DROP SEQUENCE IF EXISTS public.login_attempts_id_seq;
DROP TABLE IF EXISTS public.login_attempts;
DROP SEQUENCE IF EXISTS public.kb_templates_id_seq;
DROP TABLE IF EXISTS public.kb_templates;
DROP SEQUENCE IF EXISTS public.kb_articles_id_seq;
DROP TABLE IF EXISTS public.kb_articles;
DROP SEQUENCE IF EXISTS public.holidays_id_seq;
DROP TABLE IF EXISTS public.holidays;
DROP SEQUENCE IF EXISTS public.divisions_id_seq;
DROP TABLE IF EXISTS public.divisions;
DROP SEQUENCE IF EXISTS public.change_requests_id_seq;
DROP TABLE IF EXISTS public.change_requests;
DROP SEQUENCE IF EXISTS public.change_request_items_id_seq;
DROP TABLE IF EXISTS public.change_request_items;
DROP SEQUENCE IF EXISTS public.change_request_history_id_seq;
DROP TABLE IF EXISTS public.change_request_history;
DROP SEQUENCE IF EXISTS public.change_request_comments_id_seq;
DROP TABLE IF EXISTS public.change_request_comments;
DROP SEQUENCE IF EXISTS public.activity_log_id_seq;
DROP TABLE IF EXISTS public.activity_log;
DROP FUNCTION IF EXISTS public.working_minutes(from_ts timestamp without time zone, to_ts timestamp without time zone);
DROP FUNCTION IF EXISTS public.next_working_start(ts timestamp without time zone);
--
-- Name: next_working_start(timestamp without time zone); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.next_working_start(ts timestamp without time zone) RETURNS timestamp without time zone
    LANGUAGE plpgsql STABLE
    AS $$
DECLARE
    cur TIMESTAMP := ts;
BEGIN
    LOOP
        IF EXTRACT(DOW FROM cur) = 0
           OR EXISTS (SELECT 1 FROM holidays WHERE tanggal = cur::date) THEN
            cur := date_trunc('day', cur) + INTERVAL '1 day' + TIME '08:00';
            CONTINUE;
        END IF;
        IF cur::time < TIME '08:00' THEN
            cur := date_trunc('day', cur) + TIME '08:00';
            EXIT;
        ELSIF cur::time >= TIME '17:00' THEN
            cur := date_trunc('day', cur) + INTERVAL '1 day' + TIME '08:00';
            CONTINUE;
        ELSE
            EXIT;
        END IF;
    END LOOP;
    RETURN cur;
END;
$$;


--
-- Name: working_minutes(timestamp without time zone, timestamp without time zone); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.working_minutes(from_ts timestamp without time zone, to_ts timestamp without time zone) RETURNS integer
    LANGUAGE plpgsql STABLE
    AS $$
DECLARE
    cur TIMESTAMP;
    fin TIMESTAMP;
    total INTEGER := 0;
    day_start TIME := TIME '08:00';
    day_end TIME := TIME '17:00';
    ws TIMESTAMP;
    we TIMESTAMP;
BEGIN
    IF from_ts IS NULL OR to_ts IS NULL OR to_ts <= from_ts THEN
        RETURN 0;
    END IF;
    cur := date_trunc('day', from_ts);
    fin := to_ts;
    WHILE cur <= fin LOOP
        -- 0=Minggu .. 6=Sabtu ; lewati Minggu dan tanggal libur
        IF EXTRACT(DOW FROM cur) <> 0
           AND NOT EXISTS (SELECT 1 FROM holidays WHERE tanggal = cur::date) THEN
            ws := cur + day_start;
            we := cur + day_end;
            IF from_ts < we AND to_ts > ws THEN
                total := total + EXTRACT(EPOCH FROM (LEAST(to_ts, we) - GREATEST(from_ts, ws))) / 60;
            END IF;
        END IF;
        cur := cur + INTERVAL '1 day';
    END LOOP;
    RETURN total;
END;
$$;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: activity_log; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.activity_log (
    id integer NOT NULL,
    user_id integer,
    aksi character varying(50) NOT NULL,
    detail text,
    ip character varying(45),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: activity_log_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.activity_log_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: activity_log_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.activity_log_id_seq OWNED BY public.activity_log.id;


--
-- Name: change_request_comments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.change_request_comments (
    id integer NOT NULL,
    cr_id integer NOT NULL,
    user_id integer,
    body text NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: change_request_comments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.change_request_comments_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: change_request_comments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.change_request_comments_id_seq OWNED BY public.change_request_comments.id;


--
-- Name: change_request_history; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.change_request_history (
    id integer NOT NULL,
    cr_id integer NOT NULL,
    actor_id integer,
    from_status character varying(20),
    to_status character varying(20),
    note text NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: change_request_history_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.change_request_history_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: change_request_history_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.change_request_history_id_seq OWNED BY public.change_request_history.id;


--
-- Name: change_request_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.change_request_items (
    id integer NOT NULL,
    cr_id integer NOT NULL,
    jenis character varying(20) NOT NULL,
    uraian text NOT NULL,
    alasan text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT change_request_items_jenis_check CHECK (((jenis)::text = ANY ((ARRAY['Penambahan'::character varying, 'Perubahan'::character varying, 'Design'::character varying])::text[])))
);


--
-- Name: change_request_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.change_request_items_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: change_request_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.change_request_items_id_seq OWNED BY public.change_request_items.id;


--
-- Name: change_requests; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.change_requests (
    id integer NOT NULL,
    cr_number character varying(20) NOT NULL,
    aplikasi character varying(150) NOT NULL,
    unit character varying(100) NOT NULL,
    user_id integer NOT NULL,
    created_by integer,
    waktu_dibutuhkan timestamp without time zone,
    modul character varying(150) NOT NULL,
    fitur character varying(255) NOT NULL,
    url character varying(500),
    keterangan text NOT NULL,
    status character varying(20) DEFAULT 'open'::character varying NOT NULL,
    priority character varying(20) DEFAULT 'medium'::character varying NOT NULL,
    assigned_to integer,
    sla_due_at timestamp without time zone,
    resolved_at timestamp without time zone,
    closed_at timestamp without time zone,
    attachment_path character varying(255),
    attachment_original character varying(255),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: change_requests_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.change_requests_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: change_requests_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.change_requests_id_seq OWNED BY public.change_requests.id;


--
-- Name: divisions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.divisions (
    id integer NOT NULL,
    name character varying(100) NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: divisions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.divisions_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: divisions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.divisions_id_seq OWNED BY public.divisions.id;


--
-- Name: holidays; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.holidays (
    id integer NOT NULL,
    tanggal date NOT NULL,
    keterangan character varying(255) DEFAULT ''::character varying NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: holidays_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.holidays_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: holidays_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.holidays_id_seq OWNED BY public.holidays.id;


--
-- Name: kb_articles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.kb_articles (
    id integer NOT NULL,
    judul character varying(255) NOT NULL,
    kategori character varying(100) DEFAULT 'Lainnya'::character varying NOT NULL,
    isi text NOT NULL,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: kb_articles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.kb_articles_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: kb_articles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.kb_articles_id_seq OWNED BY public.kb_articles.id;


--
-- Name: kb_templates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.kb_templates (
    id integer NOT NULL,
    judul character varying(255) NOT NULL,
    isi text NOT NULL,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: kb_templates_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.kb_templates_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: kb_templates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.kb_templates_id_seq OWNED BY public.kb_templates.id;


--
-- Name: login_attempts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.login_attempts (
    id integer NOT NULL,
    ip character varying(45) NOT NULL,
    username character varying(50) NOT NULL,
    attempted_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    success boolean DEFAULT false
);


--
-- Name: login_attempts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.login_attempts_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: login_attempts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.login_attempts_id_seq OWNED BY public.login_attempts.id;


--
-- Name: notifications; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notifications (
    id integer NOT NULL,
    user_id integer NOT NULL,
    judul character varying(255) NOT NULL,
    isi text,
    link character varying(255),
    is_read boolean DEFAULT false,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: notifications_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.notifications_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: notifications_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.notifications_id_seq OWNED BY public.notifications.id;


--
-- Name: settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.settings (
    kunci character varying(50) NOT NULL,
    nilai character varying(255) NOT NULL,
    keterangan character varying(255) DEFAULT ''::character varying NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: ticket_comments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ticket_comments (
    id integer NOT NULL,
    ticket_id integer NOT NULL,
    user_id integer,
    body text NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: ticket_comments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.ticket_comments_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ticket_comments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.ticket_comments_id_seq OWNED BY public.ticket_comments.id;


--
-- Name: ticket_history; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ticket_history (
    id integer NOT NULL,
    ticket_id integer NOT NULL,
    actor_id integer,
    from_status character varying(20),
    to_status character varying(20),
    note text NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: ticket_history_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.ticket_history_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ticket_history_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.ticket_history_id_seq OWNED BY public.ticket_history.id;


--
-- Name: tickets; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tickets (
    id integer NOT NULL,
    ticket_number character varying(20) NOT NULL,
    title character varying(255) NOT NULL,
    description text NOT NULL,
    category character varying(100) NOT NULL,
    priority character varying(20) DEFAULT 'medium'::character varying NOT NULL,
    status character varying(20) DEFAULT 'open'::character varying NOT NULL,
    division character varying(100) NOT NULL,
    user_id integer NOT NULL,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    attachment_path character varying(255),
    attachment_original character varying(255),
    assigned_to integer,
    sla_due_at timestamp without time zone,
    resolved_at timestamp without time zone,
    closed_at timestamp without time zone,
    sla_hours integer
);


--
-- Name: tickets_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.tickets_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: tickets_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.tickets_id_seq OWNED BY public.tickets.id;


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id integer NOT NULL,
    username character varying(50) NOT NULL,
    password character varying(255),
    name character varying(100) NOT NULL,
    role character varying(20) DEFAULT 'pelapor'::character varying NOT NULL,
    division character varying(100),
    is_active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    remember_token character varying(64),
    must_change_password boolean DEFAULT false,
    auth_source character varying(10) DEFAULT 'local'::character varying NOT NULL,
    external_id integer,
    jabatan character varying(100),
    unit_kerja character varying(100),
    last_sync_at timestamp without time zone,
    CONSTRAINT users_auth_source_check CHECK (((auth_source)::text = ANY ((ARRAY['local'::character varying, 'simrs'::character varying])::text[])))
);


--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.users_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: activity_log id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.activity_log ALTER COLUMN id SET DEFAULT nextval('public.activity_log_id_seq'::regclass);


--
-- Name: change_request_comments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.change_request_comments ALTER COLUMN id SET DEFAULT nextval('public.change_request_comments_id_seq'::regclass);


--
-- Name: change_request_history id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.change_request_history ALTER COLUMN id SET DEFAULT nextval('public.change_request_history_id_seq'::regclass);


--
-- Name: change_request_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.change_request_items ALTER COLUMN id SET DEFAULT nextval('public.change_request_items_id_seq'::regclass);


--
-- Name: change_requests id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.change_requests ALTER COLUMN id SET DEFAULT nextval('public.change_requests_id_seq'::regclass);


--
-- Name: divisions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.divisions ALTER COLUMN id SET DEFAULT nextval('public.divisions_id_seq'::regclass);


--
-- Name: holidays id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.holidays ALTER COLUMN id SET DEFAULT nextval('public.holidays_id_seq'::regclass);


--
-- Name: kb_articles id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kb_articles ALTER COLUMN id SET DEFAULT nextval('public.kb_articles_id_seq'::regclass);


--
-- Name: kb_templates id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kb_templates ALTER COLUMN id SET DEFAULT nextval('public.kb_templates_id_seq'::regclass);


--
-- Name: login_attempts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.login_attempts ALTER COLUMN id SET DEFAULT nextval('public.login_attempts_id_seq'::regclass);


--
-- Name: notifications id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notifications ALTER COLUMN id SET DEFAULT nextval('public.notifications_id_seq'::regclass);


--
-- Name: ticket_comments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ticket_comments ALTER COLUMN id SET DEFAULT nextval('public.ticket_comments_id_seq'::regclass);


--
-- Name: ticket_history id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ticket_history ALTER COLUMN id SET DEFAULT nextval('public.ticket_history_id_seq'::regclass);


--
-- Name: tickets id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tickets ALTER COLUMN id SET DEFAULT nextval('public.tickets_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Data for Name: activity_log; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.activity_log (id, user_id, aksi, detail, ip, created_at) FROM stdin;
\.


--
-- Data for Name: change_request_comments; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.change_request_comments (id, cr_id, user_id, body, created_at) FROM stdin;
\.


--
-- Data for Name: change_request_history; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.change_request_history (id, cr_id, actor_id, from_status, to_status, note, created_at) FROM stdin;
\.


--
-- Data for Name: change_request_items; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.change_request_items (id, cr_id, jenis, uraian, alasan, created_at) FROM stdin;
\.


--
-- Data for Name: change_requests; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.change_requests (id, cr_number, aplikasi, unit, user_id, created_by, waktu_dibutuhkan, modul, fitur, url, keterangan, status, priority, assigned_to, sla_due_at, resolved_at, closed_at, attachment_path, attachment_original, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: divisions; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.divisions (id, name, is_active, created_at) FROM stdin;
1	IT Infrastructure	t	2026-09-24 13:35:05.4659
2	IT Development	t	2026-09-24 13:35:05.4659
4	IT Security	t	2026-09-24 13:35:05.4659
5	Network	t	2026-09-24 13:35:05.4659
14	IT / ITKOM / SIRS / EDP	t	2026-09-24 13:35:05.47716
16	IT Management	f	2026-09-24 13:35:05.47716
3	IT Support	f	2026-09-24 13:35:05.4659
20	Marketing	f	2026-09-24 13:35:05.47716
17	Operasional	f	2026-09-24 13:35:05.47716
6	System Administration	f	2026-09-24 13:35:05.4659
13	Finance	f	2026-09-24 13:35:05.47716
18	HRD	f	2026-09-24 13:35:05.47716
\.


--
-- Data for Name: holidays; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.holidays (id, tanggal, keterangan, created_at) FROM stdin;
1	2026-01-01	Tahun Baru	2026-09-21 10:03:34.795456
2	2026-05-01	Hari Buruh	2026-09-21 10:03:34.795456
3	2026-08-17	Hari Kemerdekaan	2026-09-21 10:03:34.795456
4	2026-12-25	Hari Natal	2026-09-21 10:03:34.795456
\.


--
-- Data for Name: kb_articles; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.kb_articles (id, judul, kategori, isi, created_by, created_at, updated_at) FROM stdin;
1	WiFi putus-putus di ruang meeting	Jaringan	<p><strong>Gejala:</strong> WiFi terhubung tapi tidak ada internet, berulang.</p><p><strong>Langkah:</strong></p><ol><li>Cek LED access point, restart bila merah.</li><li>Pastikan kabel LAN ke switch terkunci.</li><li>Ganti channel ke 1/6/11 bila interferensi.</li><li>Eskalasi ke Network bila > 2 AP terdampak.</li></ol>	1	2026-09-21 10:54:54.089257	2026-09-21 10:54:54.089257
2	Printer offline padahal menyala	Hardware	<p><strong>Gejala:</strong> status printer offline di Windows.</p><p><strong>Langkah:</strong></p><ol><li>Matikan SNMP pada port printer (Printer Properties > Port > Configure).</li><li>Restart Print Spooler.</li><li>Pastikan IP printer tidak berubah/dhcp conflict.</li></ol>	1	2026-09-21 10:54:54.089257	2026-09-21 10:54:54.089257
3	Outlook tidak sinkron	Software	<p><strong>Langkah:</strong></p><ol><li>Cek koneksi dan kredensial SSO.</li><li>Hapus profil mail dan buat ulang bila OST corrupt.</li><li>Batasi mailbox cache 6 bulan bila mailbox besar.</li></ol>	1	2026-09-21 10:54:54.089257	2026-09-21 10:54:54.089257
\.


--
-- Data for Name: kb_templates; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.kb_templates (id, judul, isi, created_by, created_at) FROM stdin;
1	Minta info tambahan	Mohon info tambahan agar bisa ditindaklanjuti: 1) Kapan tepatnya kejadian? 2) Pesan error persis seperti apa? 3) Apakah terjadi di perangkat lain juga? Terima kasih.	1	2026-09-21 10:54:54.08125
2	Konfirmasi selesai	Perbaikan sudah dilakukan dan dites. Mohon konfirmasi apakah kendala sudah teratasi di sisi Anda. Jika sudah beres, tiket akan kami tutup. Terima kasih.	1	2026-09-21 10:54:54.08125
3	Eskalasi — butuh sparepart/akses	Dari hasil pengecekan, penanganan butuh tindak lanjut tambahan (sparepart/akses vendor). Estimasi selesai menyusul dan akan dikabari perkembangannya. Mohon bersabar.	1	2026-09-21 10:54:54.08125
\.


--
-- Data for Name: login_attempts; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.login_attempts (id, ip, username, attempted_at, success) FROM stdin;
\.


--
-- Data for Name: notifications; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.notifications (id, user_id, judul, isi, link, is_read, created_at) FROM stdin;
\.


--
-- Data for Name: settings; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.settings (kunci, nilai, keterangan, updated_at) FROM stdin;
sla_critical_hours	4	Target jam kerja prioritas critical	2026-09-21 13:48:24.879425
sla_high_hours	24	Target jam kerja prioritas high	2026-09-21 13:48:24.879425
sla_medium_hours	72	Target jam kerja prioritas medium	2026-09-21 13:48:24.879425
sla_low_hours	120	Target jam kerja prioritas low	2026-09-21 13:48:24.879425
sla_response_minutes	60	Batas respons pertama (menit kerja)	2026-09-21 13:48:24.879425
sla_response_target	100	Target kepatuhan respons (%)	2026-09-21 13:48:24.879425
\.


--
-- Data for Name: ticket_comments; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.ticket_comments (id, ticket_id, user_id, body, created_at) FROM stdin;
\.


--
-- Data for Name: ticket_history; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.ticket_history (id, ticket_id, actor_id, from_status, to_status, note, created_at) FROM stdin;
\.


--
-- Data for Name: tickets; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.tickets (id, ticket_number, title, description, category, priority, status, division, user_id, created_by, created_at, updated_at, attachment_path, attachment_original, assigned_to, sla_due_at, resolved_at, closed_at, sla_hours) FROM stdin;
\.


--
-- Data for Name: users; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.users (id, username, password, name, role, division, is_active, created_at, updated_at, remember_token, must_change_password, auth_source, external_id, jabatan, unit_kerja, last_sync_at) FROM stdin;
4	teknisi2	$2y$10$J5C5iTrgXw9.rOv.8B7mXOxp7Ep1fjHkfY9jLKotbZryugdDSDYo.	Teknisi Dua	teknisi	IT Support	t	2026-09-21 09:52:54.274023	2026-09-21 09:52:54.274023	\N	f	local	\N	\N	\N	\N
5	teknisi3	$2y$10$J5C5iTrgXw9.rOv.8B7mXOxp7Ep1fjHkfY9jLKotbZryugdDSDYo.	Teknisi Tiga	teknisi	Network	t	2026-09-21 09:52:54.324953	2026-09-21 09:52:54.324953	\N	f	local	\N	\N	\N	\N
6	pelapor2	$2y$10$J5C5iTrgXw9.rOv.8B7mXOxp7Ep1fjHkfY9jLKotbZryugdDSDYo.	Pelapor Dua	pelapor	Finance	t	2026-09-21 09:52:54.329373	2026-09-21 09:52:54.329373	\N	f	local	\N	\N	\N	\N
8	pelapor4	$2y$10$J5C5iTrgXw9.rOv.8B7mXOxp7Ep1fjHkfY9jLKotbZryugdDSDYo.	Pelapor Empat	pelapor	Marketing	t	2026-09-21 09:52:54.338046	2026-09-21 09:52:54.338046	\N	f	local	\N	\N	\N	\N
9	pelapor5	$2y$10$J5C5iTrgXw9.rOv.8B7mXOxp7Ep1fjHkfY9jLKotbZryugdDSDYo.	Pelapor Lima	pelapor	Operasional	t	2026-09-21 09:52:54.34142	2026-09-21 09:52:54.34142	\N	f	local	\N	\N	\N	\N
10	pelapor6	$2y$10$J5C5iTrgXw9.rOv.8B7mXOxp7Ep1fjHkfY9jLKotbZryugdDSDYo.	Pelapor Enam	pelapor	IT Development	t	2026-09-21 09:52:54.345853	2026-09-21 09:52:54.345853	\N	f	local	\N	\N	\N	\N
7	pelapor3	$2y$10$J5C5iTrgXw9.rOv.8B7mXOxp7Ep1fjHkfY9jLKotbZryugdDSDYo.	Pelapor Dua	pelapor	HRD	t	2026-09-21 09:52:54.334122	2026-09-21 09:52:54.334122	\N	f	local	\N	\N	\N	\N
2	teknisi1	$2y$10$AEiK2.NZknQha/RbZz.ciOViE9ooAXPphvGWj16tjgOSj6uivx4l2	Teknisi Satu	teknisi	IT Support	t	2026-09-18 22:50:50.913577	2026-09-18 22:50:50.913577	\N	f	local	\N	\N	\N	\N
12	ryzen2004	\N	AHCMAD TSANY WICAKSONO	teknisi	IT / ITKOM / SIRS / EDP	t	2026-09-24 12:13:19.109961	2026-09-24 12:13:19.109961	\N	f	simrs	2210	Direktur	IT / ITKOM / SIRS / EDP	2026-09-24 12:44:05.583587
1	admin	$2y$10$z3gAeoE/GOHyX9tdks8U9OSa..E3.bT1ePwmgvf.uEulKHSw1ocf.	Administrator	admin	IT Management	t	2026-09-18 22:50:50.913577	2026-09-18 22:50:50.913577	\N	f	local	\N	\N	\N	\N
14	nurcahyono002	\N	NUR CAHYONO	pelapor	IT / ITKOM / SIRS / EDP	t	2026-09-24 13:41:58.537924	2026-09-24 13:41:58.537924	a579364dd61f366b69679de573523a7f92edfc61ddd420fef69be01052bd74f1	f	simrs	2148	Kepala Unit TI	IT / ITKOM / SIRS / EDP	2026-09-24 13:41:58.542455
3	pelapor1	$2y$10$tYzr6jBJt6oVI7NLj9/kl.X66s2mx4CwHrRMITzJUWIXipwT.H1kG	Pelapor Satu	pelapor	IT Development	t	2026-09-18 22:50:50.913577	2026-09-18 22:50:50.913577	\N	f	local	\N	\N	\N	\N
\.


--
-- Name: activity_log_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.activity_log_id_seq', 1, false);


--
-- Name: change_request_comments_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.change_request_comments_id_seq', 6, true);


--
-- Name: change_request_history_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.change_request_history_id_seq', 12, true);


--
-- Name: change_request_items_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.change_request_items_id_seq', 13, true);


--
-- Name: change_requests_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.change_requests_id_seq', 6, true);


--
-- Name: divisions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.divisions_id_seq', 22, true);


--
-- Name: holidays_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.holidays_id_seq', 4, true);


--
-- Name: kb_articles_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.kb_articles_id_seq', 3, true);


--
-- Name: kb_templates_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.kb_templates_id_seq', 3, true);


--
-- Name: login_attempts_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.login_attempts_id_seq', 1, false);


--
-- Name: notifications_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.notifications_id_seq', 1, false);


--
-- Name: ticket_comments_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.ticket_comments_id_seq', 1, false);


--
-- Name: ticket_history_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.ticket_history_id_seq', 1, false);


--
-- Name: tickets_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.tickets_id_seq', 1, false);


--
-- Name: users_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.users_id_seq', 14, true);


--
-- Name: activity_log activity_log_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.activity_log
    ADD CONSTRAINT activity_log_pkey PRIMARY KEY (id);


--
-- Name: change_request_comments change_request_comments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.change_request_comments
    ADD CONSTRAINT change_request_comments_pkey PRIMARY KEY (id);


--
-- Name: change_request_history change_request_history_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.change_request_history
    ADD CONSTRAINT change_request_history_pkey PRIMARY KEY (id);


--
-- Name: change_request_items change_request_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.change_request_items
    ADD CONSTRAINT change_request_items_pkey PRIMARY KEY (id);


--
-- Name: change_requests change_requests_cr_number_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.change_requests
    ADD CONSTRAINT change_requests_cr_number_key UNIQUE (cr_number);


--
-- Name: change_requests change_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.change_requests
    ADD CONSTRAINT change_requests_pkey PRIMARY KEY (id);


--
-- Name: divisions divisions_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.divisions
    ADD CONSTRAINT divisions_name_key UNIQUE (name);


--
-- Name: divisions divisions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.divisions
    ADD CONSTRAINT divisions_pkey PRIMARY KEY (id);


--
-- Name: holidays holidays_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.holidays
    ADD CONSTRAINT holidays_pkey PRIMARY KEY (id);


--
-- Name: holidays holidays_tanggal_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.holidays
    ADD CONSTRAINT holidays_tanggal_key UNIQUE (tanggal);


--
-- Name: kb_articles kb_articles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kb_articles
    ADD CONSTRAINT kb_articles_pkey PRIMARY KEY (id);


--
-- Name: kb_templates kb_templates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kb_templates
    ADD CONSTRAINT kb_templates_pkey PRIMARY KEY (id);


--
-- Name: login_attempts login_attempts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.login_attempts
    ADD CONSTRAINT login_attempts_pkey PRIMARY KEY (id);


--
-- Name: notifications notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_pkey PRIMARY KEY (id);


--
-- Name: settings settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.settings
    ADD CONSTRAINT settings_pkey PRIMARY KEY (kunci);


--
-- Name: ticket_comments ticket_comments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ticket_comments
    ADD CONSTRAINT ticket_comments_pkey PRIMARY KEY (id);


--
-- Name: ticket_history ticket_history_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ticket_history
    ADD CONSTRAINT ticket_history_pkey PRIMARY KEY (id);


--
-- Name: tickets tickets_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tickets
    ADD CONSTRAINT tickets_pkey PRIMARY KEY (id);


--
-- Name: tickets tickets_ticket_number_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tickets
    ADD CONSTRAINT tickets_ticket_number_key UNIQUE (ticket_number);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: users users_username_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_username_key UNIQUE (username);


--
-- Name: idx_activity_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_activity_created ON public.activity_log USING btree (created_at);


--
-- Name: idx_activity_user; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_activity_user ON public.activity_log USING btree (user_id);


--
-- Name: idx_comments_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_comments_created ON public.ticket_comments USING btree (created_at);


--
-- Name: idx_comments_ticket; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_comments_ticket ON public.ticket_comments USING btree (ticket_id);


--
-- Name: idx_cr_aplikasi; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cr_aplikasi ON public.change_requests USING btree (aplikasi);


--
-- Name: idx_cr_assigned; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cr_assigned ON public.change_requests USING btree (assigned_to);


--
-- Name: idx_cr_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cr_created ON public.change_requests USING btree (created_at);


--
-- Name: idx_cr_number; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cr_number ON public.change_requests USING btree (cr_number);


--
-- Name: idx_cr_priority; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cr_priority ON public.change_requests USING btree (priority);


--
-- Name: idx_cr_sla_due; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cr_sla_due ON public.change_requests USING btree (sla_due_at);


--
-- Name: idx_cr_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cr_status ON public.change_requests USING btree (status);


--
-- Name: idx_cr_user; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cr_user ON public.change_requests USING btree (user_id);


--
-- Name: idx_crc_cr; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_crc_cr ON public.change_request_comments USING btree (cr_id);


--
-- Name: idx_crc_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_crc_created ON public.change_request_comments USING btree (created_at);


--
-- Name: idx_crh_cr; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_crh_cr ON public.change_request_history USING btree (cr_id);


--
-- Name: idx_crh_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_crh_created ON public.change_request_history USING btree (created_at);


--
-- Name: idx_cri_cr; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cri_cr ON public.change_request_items USING btree (cr_id);


--
-- Name: idx_cri_jenis; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cri_jenis ON public.change_request_items USING btree (jenis);


--
-- Name: idx_history_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_history_created ON public.ticket_history USING btree (created_at);


--
-- Name: idx_history_ticket; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_history_ticket ON public.ticket_history USING btree (ticket_id);


--
-- Name: idx_holidays_tanggal; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_holidays_tanggal ON public.holidays USING btree (tanggal);


--
-- Name: idx_kb_kategori; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kb_kategori ON public.kb_articles USING btree (kategori);


--
-- Name: idx_login_ip_user_time; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_login_ip_user_time ON public.login_attempts USING btree (ip, username, attempted_at);


--
-- Name: idx_notif_user; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_notif_user ON public.notifications USING btree (user_id, is_read, id DESC);


--
-- Name: idx_tickets_assigned; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_tickets_assigned ON public.tickets USING btree (assigned_to);


--
-- Name: idx_tickets_category; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_tickets_category ON public.tickets USING btree (category);


--
-- Name: idx_tickets_created_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_tickets_created_at ON public.tickets USING btree (created_at);


--
-- Name: idx_tickets_division; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_tickets_division ON public.tickets USING btree (division);


--
-- Name: idx_tickets_priority; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_tickets_priority ON public.tickets USING btree (priority);


--
-- Name: idx_tickets_resolved_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_tickets_resolved_at ON public.tickets USING btree (resolved_at);


--
-- Name: idx_tickets_sla_due; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_tickets_sla_due ON public.tickets USING btree (sla_due_at);


--
-- Name: idx_tickets_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_tickets_status ON public.tickets USING btree (status);


--
-- Name: idx_tickets_user_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_tickets_user_id ON public.tickets USING btree (user_id);


--
-- Name: idx_users_active; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_users_active ON public.users USING btree (is_active);


--
-- Name: idx_users_auth_source; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_users_auth_source ON public.users USING btree (auth_source);


--
-- Name: idx_users_external; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_users_external ON public.users USING btree (auth_source, external_id) WHERE (external_id IS NOT NULL);


--
-- Name: idx_users_role; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_users_role ON public.users USING btree (role);


--
-- Name: activity_log activity_log_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.activity_log
    ADD CONSTRAINT activity_log_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: change_request_comments change_request_comments_cr_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.change_request_comments
    ADD CONSTRAINT change_request_comments_cr_id_fkey FOREIGN KEY (cr_id) REFERENCES public.change_requests(id) ON DELETE CASCADE;


--
-- Name: change_request_comments change_request_comments_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.change_request_comments
    ADD CONSTRAINT change_request_comments_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: change_request_history change_request_history_actor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.change_request_history
    ADD CONSTRAINT change_request_history_actor_id_fkey FOREIGN KEY (actor_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: change_request_history change_request_history_cr_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.change_request_history
    ADD CONSTRAINT change_request_history_cr_id_fkey FOREIGN KEY (cr_id) REFERENCES public.change_requests(id) ON DELETE CASCADE;


--
-- Name: change_request_items change_request_items_cr_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.change_request_items
    ADD CONSTRAINT change_request_items_cr_id_fkey FOREIGN KEY (cr_id) REFERENCES public.change_requests(id) ON DELETE CASCADE;


--
-- Name: change_requests change_requests_assigned_to_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.change_requests
    ADD CONSTRAINT change_requests_assigned_to_fkey FOREIGN KEY (assigned_to) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: change_requests change_requests_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.change_requests
    ADD CONSTRAINT change_requests_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: change_requests change_requests_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.change_requests
    ADD CONSTRAINT change_requests_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: kb_articles kb_articles_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kb_articles
    ADD CONSTRAINT kb_articles_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: kb_templates kb_templates_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kb_templates
    ADD CONSTRAINT kb_templates_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: notifications notifications_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: ticket_comments ticket_comments_ticket_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ticket_comments
    ADD CONSTRAINT ticket_comments_ticket_id_fkey FOREIGN KEY (ticket_id) REFERENCES public.tickets(id) ON DELETE CASCADE;


--
-- Name: ticket_comments ticket_comments_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ticket_comments
    ADD CONSTRAINT ticket_comments_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: ticket_history ticket_history_actor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ticket_history
    ADD CONSTRAINT ticket_history_actor_id_fkey FOREIGN KEY (actor_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: ticket_history ticket_history_ticket_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ticket_history
    ADD CONSTRAINT ticket_history_ticket_id_fkey FOREIGN KEY (ticket_id) REFERENCES public.tickets(id) ON DELETE CASCADE;


--
-- Name: tickets tickets_assigned_to_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tickets
    ADD CONSTRAINT tickets_assigned_to_fkey FOREIGN KEY (assigned_to) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: tickets tickets_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tickets
    ADD CONSTRAINT tickets_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: tickets tickets_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tickets
    ADD CONSTRAINT tickets_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--

\unrestrict eYPfmx07xz1p9E6FE8Q1Ggw4dtvOtyXqfEiOX3hazhPaCMcwLoontioqPg0oyeO

