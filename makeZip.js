const fs = require("fs");
const archiver = require("archiver");
const path = require("path");

class ZipCreator {
  constructor(config) {
    this.validateConfig(config);

    const {
      slug,
      mainFile,
      version = this.getPluginVersion(mainFile),
      includes = [],
      excludePatterns = [],
    } = config;

    this.slug = slug;
    this.version = version;
    this.distDir = path.join(__dirname, "dist");
    this.zipName = path.join(this.distDir, `${this.slug}-${this.version}.zip`);
    this.includes = includes;
    this.mainfile = mainFile;

    // Ensure dist directory exists
    if (!fs.existsSync(this.distDir)) {
      fs.mkdirSync(this.distDir, { recursive: true });
    }

    // Helper function to create complete folder exclusion patterns
    const excludeFolder = (path) => [`${path}/**/*`, `${path}/*`, path];

    this.excludePatterns = [
      // Source files that are compiled
      //   ...excludeFolder("assets/backend/raw"),
      // Add any additional default patterns
      ...excludePatterns,
    ];

    this.output = fs.createWriteStream(this.zipName);
    this.archive = archiver("zip", {
      zlib: { level: 9 }, // Sets the compression level
    });
  }

  getPluginVersion(mainFile) {
    try {
      const phpFile = fs.readFileSync(path.join(__dirname, mainFile), "utf8");
      const versionMatch = phpFile.match(/Version:\s*([0-9.]+)/i);
      if (!versionMatch) {
        throw new Error("Could not find version in plugin file");
      }
      return versionMatch[1];
    } catch (err) {
      throw new Error(`Failed to get plugin version: ${err.message}`);
    }
  }

  validateConfig(config) {
    if (!config?.slug) {
      throw new Error("Plugin slug is required");
    }
    if (!config?.mainFile) {
      throw new Error("Main file is required");
    }
  }

  setupEventListeners() {
    // Setup the archive event listeners
    this.output.on("close", () => {
      console.log(
        "✅ Archive has been finalized and output file descriptor closed"
      );
      console.log(`📦 Total bytes: ${this.archive.pointer()}`);
    });

    this.output.on("end", () => {
      console.log("Data has been drained");
    });

    this.archive.on("warning", (err) => {
      if (err.code === "ENOENT") {
        console.warn("⚠️ Warning:", err);
      } else {
        throw err;
      }
    });

    this.archive.on("error", (err) => {
      console.error("❌ Error:", err);
      throw err;
    });

    this.archive.pipe(this.output);
  }

  shouldExclude(filePath, dirName) {
    const fullPath = `${dirName}/${filePath}`;

    const isExcluded = this.excludePatterns.some((pattern) => {
      const regexPattern = pattern
        .replace(/\./g, "\\.")
        .replace(/\*\*/g, ".*")
        .replace(/\*/g, "[^/]*")
        .replace(/\?/g, ".");

      const regex = new RegExp(`^${regexPattern}$`);
      const matches = regex.test(fullPath);
      if (matches) {
        console.log(`🎯 Match found: ${fullPath} matches pattern ${pattern}`);
      }
      return matches;
    });

    return isExcluded;
  }

  async addFile(filePath, archivePath) {
    const fullPath = path.join(__dirname, filePath);

    try {
      await fs.promises.access(fullPath, fs.constants.F_OK);
      this.archive.append(fs.createReadStream(fullPath), {
        name: path.join(this.slug, archivePath),
      });
      console.log(`📄 Added file: ${filePath}`);
    } catch (err) {
      console.warn(`⚠️ File ${filePath} not found, skipping...`);
    }
  }

  addDirectory(dirName) {
    const dirPath = path.join(__dirname, dirName);

    try {
      if (fs.existsSync(dirPath)) {
        this.archive.directory(
          dirPath,
          path.join(this.slug, dirName),
          (entry) => {
            if (this.shouldExclude(entry.name, dirName)) {
              console.log(`Excluding: ${dirName}/${entry.name}`);
              return false;
            }
            console.log(`Including: ${dirName}/${entry.name}`);
            return entry;
          }
        );
      } else {
        console.warn(`⚠️ Directory ${dirName} not found, skipping...`);
      }
    } catch (err) {
      console.error(`❌ Error processing directory ${dirName}:`, err);
    }
  }

  async create() {
    console.log("📦 Starting zip creation...");

    try {
      this.setupEventListeners();

      // Add main plugin file
      await this.addFile(this.mainfile, this.mainfile);

      // Add all included directories
      this.includes.forEach((dir) => this.addDirectory(dir));

      await this.archive.finalize();
    } catch (err) {
      console.error("❌ Error creating zip:", err);
      throw err;
    }
  }
}

// Usage
const pluginConfig = {
  slug: "poststation",
  mainFile: "poststation.php",
  includes: ["assets", "includes"],
  excludePatterns: [],
};

const zipCreator = new ZipCreator(pluginConfig);
zipCreator.create().catch((err) => {
  console.error("Failed to create zip:", err);
  process.exit(1);
});
